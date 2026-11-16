<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Web\FeedSearchRequest;
use App\Models\User;
use App\Services\V1\UserOnlineService;
use App\Services\V1\UserSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class FeedController extends Controller
{
    /**
     * FIX (N+1 к Redis): онлайн-статусы всей страницы проверяем одним
     * pipeline через UserOnlineService, а не аксессором is_online,
     * который делал отдельный Redis EXISTS на каждого юзера.
     */
    public function __construct(
        private readonly UserOnlineService $onlineService,
    ) {}

    /**
     * Поиск анкет с кэшированием готового JSON-ответа.
     *
     * АРхитектура:
     *  - SQL отдаёт Eloquent-модели с relations (photos, profile, city).
     *  - Контроллер трансформирует их в плоский массив.
     *  - В Redis кэшируется уже ГОТОВЫЙ JSON-ответ (на 60 сек).
     *  - При cache hit отдаём JSON как есть — никаких проблем с relations.
     *
     * Кэш-инвалидация: версионные ключи (см. UserSearchService::invalidateUserCache /
     * invalidateAllCache — O(1) через INCR). Вызывается из Observers на
     * Swipe/UserBlock/UserProfile/UserPreference/UserMatch. Контроллер только читает кэш.
     */
    public function search(FeedSearchRequest $request, UserSearchService $searchService): JsonResponse
    {
        try {
            $filters = $request->getFilters();
            $authUser = $request->user();

            $page = max(1, (int) $request->input('page', 1));
            $perPage = $searchService->normalizePerPage($request->input('per_page'));

            // Ключ кэша — берём из Service (единый источник истины)
            $cacheKey = $searchService->buildCacheKey($authUser, $filters, $page, $perPage);
            $cacheTtl = config('search.cache_ttl', 60);

            // ===== 1. ПРОБУЕМ ДОСТАТЬ ИЗ REDIS =====
            $cached = $this->safeRedisGet($cacheKey);
            if ($cached !== null) {
                $payload = json_decode($cached, true);
                if (is_array($payload) && isset($payload['users'])) {
                    Log::debug('FeedController: cache HIT', [
                        'cache_key' => $cacheKey,
                        'user_id'   => $authUser?->id,
                    ]);
                    return response()->json($payload);
                }
                // Битый кэш — удаляем
                $this->safeRedisDel($cacheKey);
            }

            // ===== 2. CACHE MISS — ВЫПОЛНЯЕМ SQL =====
            $users = $searchService->searchPaginated($authUser, $filters, $page, $perPage);

            if ($users->isEmpty()) {
                // Пустой результат — кэшируем пустоту на короткий TTL (15 сек)
                // чтобы не долбить БД при одинаковых фильтрах.
                $emptyPayload = $this->buildEmptyPayload($page, $perPage);
                $this->safeRedisSetex($cacheKey, 15, json_encode($emptyPayload, JSON_UNESCAPED_UNICODE));

                Log::info('FeedController: search returned empty', [
                    'user_id'  => $authUser?->id,
                    'filters'  => $filters,
                    'page'     => $page,
                ]);
                return response()->json($emptyPayload);
            }

            // ===== 3. ОНЛАЙН-СТАТУСЫ СТРАНИЦЫ — ОДНИМ PIPELINE =====
            // Раньше аксессор is_online делал 20 последовательных Redis EXISTS
            // на страницу (по одному на каждую карточку).
            $onlineStatuses = $this->onlineService->getOnlineStatuses(
                $users->getCollection()->pluck('id')->all()
            );

            // ===== 4. ТРАНСФОРМИРУЕМ МОДЕЛИ В ПЛОСКИЙ МАССИВ =====
            $mappedUsers = $users->getCollection()
                ->map(fn ($u) => $this->transformUser($u, $onlineStatuses))
                ->values();

            $payload = [
                'users'        => $mappedUsers,
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'total'        => $users->total(),
                'per_page'     => $users->perPage(),
            ];

            // ===== 5. КЭШИРУЕМ ГОТОВЫЙ JSON =====
            $this->safeRedisSetex(
                $cacheKey,
                $cacheTtl,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            Log::debug('FeedController: cache MISS, SQL executed', [
                'cache_key' => $cacheKey,
                'user_id'   => $authUser?->id,
                'total'     => $users->total(),
            ]);

            return response()->json($payload);

        } catch (Throwable $e) {
            Log::error('FeedController: search failed', [
                'user_id' => $authUser?->id ?? null,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error'   => true,
                'message' => 'Search temporarily unavailable',
                config('app.debug') ? 'debug' : '_' => [
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                    'trace'   => $e->getTraceAsString(),
                ],
            ], 500);
        }
    }

    /**
     * Случайные аватары для префилла регистрационной формы.
     *
     * FIX (DoS-вектор на CPU БД): раньше — ORDER BY RANDOM() по всей таблице
     * users + 2 whereHas-подзапроса на ПУБЛИЧНОМ роуте без авторизации.
     * PostgreSQL вычислял random() и сортировал ВСЕ подходящие строки на каждый
     * запрос (ORDER BY RANDOM() нельзя ускорить индексом; inRandomOrder() —
     * это одно и то же). Теперь:
     *   1. Пул из N случайных аватаров собирается по случайным PK
     *      (random_int по [1, MAX(id)] + WHERE IN — работает по индексу PK).
     *   2. Пул кэшируется в Redis на 10 минут.
     *   3. Каждый запрос отдаёт 2 случайных URL из кэшированного пула.
     * Fallback на ORDER BY RANDOM() остаётся только для маленькой/пустой БД
     * и выполняется не чаще одного раза в 10 минут (при промахе кэша).
     */
    public function randomAvatars(): JsonResponse
    {
        try {
            $cacheKey = 'random_avatars:pool';
            $poolSize = 30;
            $poolTtl = 600;

            $pool = null;
            $cached = $this->safeRedisGet($cacheKey);
            if ($cached !== null) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    $pool = $decoded;
                }
            }

            if ($pool === null) {
                $pool = $this->buildAvatarPool($poolSize);
                $this->safeRedisSetex(
                    $cacheKey,
                    $poolTtl,
                    json_encode($pool, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            }

            if (empty($pool)) {
                return response()->json(['avatars' => []]);
            }

            shuffle($pool);

            return response()->json(['avatars' => array_slice($pool, 0, 2)]);

        } catch (Throwable $e) {
            Log::error('FeedController: randomAvatars failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['avatars' => []]);
        }
    }

    // ============================================================
    // TRANSFORMATION HELPERS
    // ============================================================

    /**
     * Трансформирует User в плоский массив для фронтенда.
     *
     * FIX #1, #2: безопасный доступ к profile через ?->, fallback на defaults.
     * Раньше: $user->profile->age → 500 если profile null.
     * Теперь: $user->profile?->age ?? '?'.
     */
    private function transformUser(User $user, array $onlineStatuses): array
    {
        $profile = $user->profile;
        $primaryPhoto = $user->photos->isNotEmpty() ? $user->photos->first() : null;

        // Семантика та же, что у аксессора is_online: Redis-ключ существует
        // ИЛИ last_seen не старше 5 минут. Но статус уже посчитан батчем —
        // без N+1 раундтрипов к Redis (при падении Redis сервис вернёт [],
        // и останется fallback по last_seen).
        $isOnline = ($onlineStatuses[$user->id] ?? false)
            || ($user->last_seen !== null && $user->last_seen->gt(now()->subMinutes(5)));

        return [
            'id'            => $user->id,
            'slug'          => $user->slug,
            'name'          => $user->name ?? 'Аноним',
            'age'           => $profile?->age ?? '?',
            'city'          => $profile?->city?->name ?? 'Город не указан',
            'is_online'     => $isOnline,
            'has_premium'   => $user->has_active_premium,
            'photo'         => $primaryPhoto?->medium_url,
            'photos_count'  => $user->photos_count ?? 0,
            'zodiac'        => $profile?->zodiac_name ?? 'Знак не указан',
            'distance'      => isset($user->distance) ? $user->distance . ' км' : null,
            'status_text'   => $isOnline ? 'Онлайн' : 'Был недавно',
        ];
    }

    /**
     * Строит payload для пустого результата.
     */
    private function buildEmptyPayload(int $page, int $perPage): array
    {
        return [
            'users'        => [],
            'current_page' => $page,
            'last_page'    => $page,
            'total'        => 0,
            'per_page'     => $perPage,
        ];
    }

    // ============================================================
    // AVATAR POOL
    // ============================================================

    /**
     * Собирает пул URL аватаров по случайным PK.
     *
     * @return string[] — массив thumb_url
     */
    private function buildAvatarPool(int $size): array
    {
        $maxId = (int) User::whereNull('deleted_at')->max('id');

        if ($maxId >= 1) {
            // Сэмплируем случайные PK (с запасом: часть не пройдёт фильтры).
            $candidateIds = collect(range(1, $size * 5))
                ->map(fn () => random_int(1, $maxId))
                ->unique()
                ->values()
                ->all();

            $avatars = User::query()
                ->select('users.id')
                ->whereIn('users.id', $candidateIds)
                ->whereNull('users.deleted_at')
                ->where('users.status', User::STATUS_ACTIVE)
                ->whereHas('profile', fn ($q) => $q->where('gender', 'female'))
                ->with([
                    'photos' => fn ($q) => $q->select('id', 'user_id', 'is_primary', 'path_thumb')
                        ->where('status', 'approved')
                        ->orderByDesc('is_primary')
                        ->orderByDesc('id'),
                ])
                ->get()
                ->map(fn ($u) => $u->photos->first()?->thumb_url)
                ->filter()
                ->values()
                ->all();

            if (count($avatars) >= 2) {
                return array_slice($avatars, 0, $size);
            }
        }

        // Маленькая/пустая база: fallback на сортировку RANDOM(). Безопасно:
        // выполняется только при промахе кэша пула — максимум раз в 10 минут.
        return User::query()
            ->select('users.id')
            ->whereHas('profile', fn ($q) => $q->where('gender', 'female'))
            ->whereHas('photos', fn ($q) => $q->where('status', 'approved'))
            ->with([
                'photos' => fn ($q) => $q->select('id', 'user_id', 'is_primary', 'path_thumb')
                    ->where('status', 'approved')
                    ->orderByDesc('is_primary')
                    ->orderByDesc('id'),
            ])
            ->whereNull('users.deleted_at')
            ->where('users.status', User::STATUS_ACTIVE)
            ->orderByRaw('RANDOM()')
            ->limit($size)
            ->get()
            ->map(fn ($u) => $u->photos->first()?->thumb_url)
            ->filter()
            ->values()
            ->all();
    }

    // ============================================================
    // REDIS HELPERS (с защитой от падения)
    // ============================================================

    /**
     * Безопасное чтение из Redis.
     */
    private function safeRedisGet(string $key): ?string
    {
        try {
            $value = Redis::get($key);
            return $value !== false ? $value : null;
        } catch (Throwable $e) {
            Log::warning('FeedController: Redis read failed, falling back to SQL', [
                'cache_key' => $key,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Безопасная запись в Redis с TTL.
     */
    private function safeRedisSetex(string $key, int $ttl, string $value): void
    {
        try {
            Redis::setex($key, $ttl, $value);
        } catch (Throwable $e) {
            Log::warning('FeedController: cache write failed, continuing without cache', [
                'cache_key' => $key,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Безопасное удаление ключа.
     */
    private function safeRedisDel(string $key): void
    {
        try {
            Redis::del($key);
        } catch (Throwable $e) {
            // silent — уже логируем выше
        }
    }
}
