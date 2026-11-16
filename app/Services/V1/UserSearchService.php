<?php

namespace App\Services\V1;

use App\Models\Interest;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

/**
 * Сервис поиска анкет пользователей.
 *
 * Архитектура:
 *  - search()              — возвращает Builder (без кэша, без пагинации).
 *  - searchPaginated()     — возвращает Paginator (без кэша). Используется контроллером.
 *  - invalidateUserCache()  — сброс кэша конкретного юзера (O(1), через INCR версии).
 *  - invalidateAllCache()   — сброс ВСЕГО кэша (O(1), глобальная версия).
 *
 * Кэширование — в FeedController (готовый JSON на 60 сек).
 * Здесь — только построение запроса и версионирование ключей.
 *
 * ИНВАЛИДАЦИЯ ЧЕРЕЗ ВЕРСИИ (а не через SCAN/DEL):
 *  Ключ кэша содержит глобальную версию и личную версию юзера.
 *  Инвалидация = INCR счётчика версии (одна команда Redis, O(1)).
 *  Следующее чтение строит НОВЫЙ ключ → cache miss → свежие данные из БД.
 *  Старые ключи протухают сами по TTL (60 сек) — удалять их не нужно.
 *
 *  Так было раньше: обсерверы на каждый свайп/блок/апдейт профиля запускали
 *  SCAN по ВСЕМУ keyspace Redis (+ фоллбэк на блокирующий KEYS). Свайп — самая
 *  горячая write-операция дейтинга: при 20-50 свайпах/сек Redis (однопоточный!)
 *  упирался в 100% CPU и тормозил кэш, онлайн-статусы, rate-limiter'ы и Horizon.
 *
 * ВАЖНО про производительность:
 *  - Использует partial indexes из миграций #1, #2, #3 (users_search_candidates_idx,
 *    user_profiles_gender_birthdate_active_idx, user_prefs_search_visible_idx, и т.д.)
 *  - Интересы ищет через классический pivot user_interests + whereHas.
 *    Использует INDEX(user_id) и INDEX(interest_id) на pivot — работает на миллионах
 *    юзеров с 5-10 интересами у каждого.
 *  - Свайпы/блоки исключает через whereNotExists (NOT IN с NULL = бага).
 */
class UserSearchService
{
    /** Cache prefix — унифицированный, используется и здесь, и в FeedController. */
    private const CACHE_PREFIX = 'feed_search';

    /** Ключ глобальной версии кэша (INCR = полный сброс кэша для всех). */
    private const VER_GLOBAL_KEY = 'feed_search:ver:all';

    /** Префикс ключа личной версии юзера (INCR = сброс кэша одного юзера). */
    private const VER_USER_PREFIX = 'feed_search:ver:u';

    /** TTL ключа личной версии (сутки с последней инвалидации).
     *  Ключи данных живут 60 сек — задолго до экспайра версии они уже мертвы. */
    private const VER_USER_TTL = 86400;

    public const VISIBILITY_FIELDS = [
        'hide_from_search',
        'is_invisible',
        'visibility_gender',
        'visibility_age_min',
        'visibility_age_max',
    ];

    public const SEARCH_PREFERENCE_FIELDS = [
        'preferred_gender',
        'preferred_age_min',
        'preferred_age_max',
        'preferred_distance_km',
        'search_filters',
    ];

    // ============================================================
    // ОСНОВНОЙ ПОИСК
    // ============================================================

    public function search(?User $user, array $filters = []): Builder
    {
        $filters = $this->normalizeFilters($filters);

        $query = User::query()
            ->select('users.*')
            ->join('user_profiles', 'users.id', '=', 'user_profiles.user_id')
            ->join('user_preferences', 'users.id', '=', 'user_preferences.user_id')
            ->whereNull('users.deleted_at')
            ->whereNull('user_profiles.deleted_at')
            ->where('users.status', User::STATUS_ACTIVE)
            ->where('users.role', User::ROLE_USER)
            ->where('users.has_completed_onboarding', true)
            ->whereNotNull('user_profiles.gender')
            ->whereNotNull('user_profiles.birth_date')
            ->where('user_preferences.hide_from_search', false)
            ->where('user_preferences.is_invisible', false);

        if ($user) {
            $this->applyUserContext($query, $user);
        }

        $this->applyBaseFilters($query, $filters);
        $this->applyLocationFilter($query, $filters);
        $this->applyAdvancedFilters($query, $filters);
        $this->applySorting($query, $filters);

        $query->with([
            'profile.city',
            // ВАЖНО: здесь НЕ должно быть ->limit(1)!
            // LIMIT внутри constrained eager load применяется к ОДНОМУ общему запросу
            // WHERE user_id IN (...) — фото получил бы только первый юзер страницы
            // (ловушка Laravel, см. Chat::latestMessage). У юзера в дейтинге
            // мало фото (единицы), поэтому грузим все approved и берём первое в PHP.
            'photos' => fn($q) => $q->where('status', 'approved')
                ->orderByDesc('is_primary')
                ->orderByDesc('id'),
        ])->withCount([
            'photos' => fn($q) => $q->where('status', 'approved'),
        ]);

        return $query;
    }

    public function searchPaginated(
        ?User $user,
        array $filters = [],
        int $page = 1,
        ?int $perPage = null
    ): LengthAwarePaginator {
        $perPage = $this->normalizePerPage($perPage);
        $page = max(1, $page);

        return $this->search($user, $filters)
            ->paginate($perPage, ['*'], 'page', $page);
    }

    // ============================================================
    // КОНТЕКСТ ТЕКУЩЕГО ЮЗЕРА
    // ============================================================

    private function applyUserContext(Builder $query, User $user): void
    {
        $query->where('users.id', '!=', $user->id);

        // Исключаем засвайпанных — whereNotExists (нечувствителен к NULL).
        // rewinded_at IS NULL: отмотанный (rewind) свайп не должен скрывать анкету
        // из выдачи — задокументированное поведение поля rewinded_at (миграция swipes).
        $query->whereNotExists(function ($q) use ($user) {
            $q->select(DB::raw(1))
                ->from('swipes')
                ->whereColumn('swipes.target_user_id', 'users.id')
                ->where('swipes.user_id', $user->id)
                ->whereNull('swipes.rewinded_at');
        });

        // Исключаем заблокированных
        $query->whereNotExists(function ($q) use ($user) {
            $q->select(DB::raw(1))
                ->from('user_blocks')
                ->whereColumn('user_blocks.blocked_id', 'users.id')
                ->where('user_blocks.blocker_id', $user->id);
        });

        // Reverse-фильтр: visibility-настройки других юзеров
        $userGender = $user->profile?->gender;
        $userAge = $user->profile?->age;

        $query->where(function ($q) use ($userGender) {
            $q->whereNull('user_preferences.visibility_gender')
              ->orWhere('user_preferences.visibility_gender', UserPreference::GENDER_ANY);
            if ($userGender !== null) {
                $q->orWhere('user_preferences.visibility_gender', $userGender);
            }
        });

        if ($userAge !== null) {
            $query->where(function ($q) use ($userAge) {
                $q->whereNull('user_preferences.visibility_age_min')
                  ->orWhere('user_preferences.visibility_age_min', '<=', $userAge);
            });

            $query->where(function ($q) use ($userAge) {
                $q->whereNull('user_preferences.visibility_age_max')
                  ->orWhere('user_preferences.visibility_age_max', '>=', $userAge);
            });
        }
    }

    // ============================================================
    // БАЗОВЫЕ ФИЛЬТРЫ
    // ============================================================

    private function applyBaseFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['gender']) && $filters['gender'] !== UserPreference::GENDER_ANY) {
            $query->where('user_profiles.gender', $filters['gender']);
        }

        [$ageFrom, $ageTo] = $this->normalizeAgeRange(
            (int)($filters['age_from'] ?? config('search.age_min', 18)),
            (int)($filters['age_to'] ?? config('search.age_max', 99))
        );

        $maxDate = Carbon::now()->subYears($ageFrom)->format('Y-m-d');
        $minDate = Carbon::now()->subYears($ageTo)->format('Y-m-d');
        $query->whereBetween('user_profiles.birth_date', [$minDate, $maxDate]);

        if (!empty($filters['country_id'])) {
            $query->where('user_profiles.country_id', (int)$filters['country_id']);
        }
        if (!empty($filters['city_id'])) {
            $query->where('user_profiles.city_id', (int)$filters['city_id']);
        }

        if (!empty($filters['dating_goal']) && $filters['dating_goal'] !== 'any') {
            $query->whereJsonContains('user_profiles.dating_goals', $filters['dating_goal']);
        }

        if (!empty($filters['activity'])) {
            if ($filters['activity'] === 'online') {
                $query->where('users.last_seen', '>=', now()->subMinutes(5));
            } elseif ($filters['activity'] === 'recently') {
                $query->where('users.last_seen', '>=', now()->subDays(1));
            }
        }

        if ($this->isTruthy($filters['is_new'] ?? null)) {
            $days = config('search.new_users_days', 7);
            $query->where('users.created_at', '>=', now()->subDays($days));
        }

        if ($this->isTruthy($filters['verified_only'] ?? null)) {
            $query->where('users.is_verified', true);
        }
    }

    private function applyLocationFilter(Builder $query, array $filters): void
    {
        $lat = isset($filters['lat']) ? (float)$filters['lat'] : null;
        $lng = isset($filters['lng']) ? (float)$filters['lng'] : null;
        $radiusKm = isset($filters['radius_km']) ? (int)$filters['radius_km'] : null;

        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            $lat = null;
        }
        if ($lng !== null && ($lng < -180 || $lng > 180)) {
            $lng = null;
        }

        if ($lat !== null && $lng !== null && $radiusKm !== null && $radiusKm > 0) {
            $radiusMeters = $radiusKm * 1000;

            $query->whereNotNull('user_profiles.location')
                ->whereRaw(
                    'ST_DWithin(user_profiles.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
                    [$lng, $lat, $radiusMeters]
                )
                ->selectRaw(
                    'ROUND(ST_DistanceSphere(user_profiles.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geometry) / 1000, 1) as distance',
                    [$lng, $lat]
                );
        }
    }

    /**
     * Расширенные фильтры: рост, вес, атрибуты профиля, интересы.
     *
     * ИНТЕРЕСЫ — через классический pivot user_interests + whereHas.
     * Использует INDEX(user_id) и INDEX(interest_id) на pivot —
     * работает на миллионах юзеров с 5-10 интересами у каждого.
     */
    private function applyAdvancedFilters(Builder $query, array $filters): void
    {
        $this->applyRangeFilter($query, $filters, 'height', 'user_profiles.height', 0, 300);
        $this->applyRangeFilter($query, $filters, 'weight', 'user_profiles.weight', 0, 500);

        $scalarArrayFilters = [
            'body_type', 'eye_color', 'hair_color', 'relationship_status',
            'children_status', 'pets', 'housing', 'has_car',
            'education_level', 'income', 'smoking', 'alcohol', 'zodiac_sign',
        ];
        foreach ($scalarArrayFilters as $field) {
            if (!empty($filters[$field]) && is_array($filters[$field])) {
                $intValues = array_filter(array_map('intval', $filters[$field]));
                if (!empty($intValues)) {
                    $query->whereIn("user_profiles.{$field}", $intValues);
                }
            }
        }

        // languages, sports, body_decorations — через нативный whereJsonContains
        $jsonArrayFilters = ['languages', 'sports', 'body_decorations'];
        foreach ($jsonArrayFilters as $field) {
            if (!empty($filters[$field]) && is_array($filters[$field])) {
                $intValues = array_filter(array_map('intval', $filters[$field]));
                if (!empty($intValues)) {
                    foreach ($intValues as $value) {
                        $query->whereJsonContains("user_profiles.{$field}", $value);
                    }
                }
            }
        }

        // ИНТЕРЕСЫ — через pivot user_interests (whereHas).
        // Генерирует WHERE EXISTS (SELECT 1 FROM user_interests ... WHERE ... IN (...))
        if (!empty($filters['interests']) && is_array($filters['interests'])) {
            $interestIds = array_filter(array_map('intval', $filters['interests']));
            if (!empty($interestIds)) {
                $query->whereHas('interests', function ($q) use ($interestIds) {
                    $q->whereIn('interests.id', $interestIds);
                    // УБРАНО: ->where('interests.status', Interest::STATUS_APPROVED)
                    // Больше нет колонки status — все интересы валидны по умолчанию
                });
            }
        }
    }

    private function applyRangeFilter(
        Builder $query,
        array $filters,
        string $fieldKey,
        string $column,
        int $min,
        int $max
    ): void {
        $from = $filters["{$fieldKey}_from"] ?? null;
        $to = $filters["{$fieldKey}_to"] ?? null;

        if ($from === null && $to === null) {
            return;
        }

        $fromVal = $from !== null ? max($min, (int)$from) : $min;
        $toVal = $to !== null ? min($max, (int)$to) : $max;

        if ($fromVal > $toVal) {
            [$fromVal, $toVal] = [$toVal, $fromVal];
        }

        $query->whereBetween($column, [$fromVal, $toVal]);
    }

    private function applySorting(Builder $query, array $filters): void
    {
        $sort = $filters['sort'] ?? 'last_seen';

        switch ($sort) {
            case 'distance':
                if ($this->hasDistanceColumn($query)) {
                    $query->orderBy('distance', 'asc');
                } else {
                    $query->orderByDesc('users.last_seen');
                }
                break;
            case 'new':
                $query->orderByDesc('users.created_at');
                break;
            case 'last_seen':
            default:
                $query->orderByDesc('users.last_seen');
                break;
        }
    }

    // ============================================================
    // ИНВАЛИДАЦИЯ КЭША — ВЕРСИОННЫЕ КЛЮЧИ (O(1))
    // ============================================================

    /**
     * Сбросить кэш ленты конкретного юзера.
     *
     * O(1): INCR счётчика его личной версии. Следующий запрос юзера
     * построит новый ключ кэша (со свежей версией) и сделает cache miss.
     *
     * Вызывается из обсерверов: SwipeObserver, UserBlockObserver,
     * UserMatchObserver, UserProfileObserver, UserPreferenceObserver —
     * поэтому должна быть дешёвой (раньше здесь был SCAN всего keyspace).
     */
    public function invalidateUserCache(int $userId): void
    {
        try {
            $key = self::VER_USER_PREFIX . $userId;
            Redis::incr($key);
            Redis::expire($key, self::VER_USER_TTL);
        } catch (\Throwable $e) {
            // Redis недоступен: кэш всё равно не прочитается — деградация
            // мягкая, максимум 60 сек устаревших данных до истечения TTL.
            Log::warning('UserSearch: user cache version bump failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Сбросить кэш ленты ВСЕХ юзеров.
     *
     * O(1): INCR глобальной версии — все ключи мгновенно устаревают,
     * потому что версия входит в ключ (см. buildCacheKey).
     *
     * Вызывается при bulk-изменениях visibility-полей, soft-delete юзеров
     * и из InvalidateAllSearchCacheJob. Раньше = полный SCAN keyspace +
     * DEL всех ключей + thundering herd в тяжёлый SQL поиска; теперь
     * реквесты разлетаются во времени естественным образом.
     */
    public function invalidateAllCache(): void
    {
        try {
            Redis::incr(self::VER_GLOBAL_KEY);

            Log::info('UserSearch: global cache version bumped (all feed cache invalidated)');
        } catch (\Throwable $e) {
            Log::warning('UserSearch: global cache version bump failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Прочитать версии кэша (глобальную и личную юзера) одним MGET.
     * Нет ключа = 0 (счётчик ещё не инкрементировали — базовая версия).
     */
    private function readVersions(int|string $userId): array
    {
        $keys = [self::VER_GLOBAL_KEY];
        if (is_int($userId)) {
            $keys[] = self::VER_USER_PREFIX . $userId;
        }

        try {
            $values = Redis::mget($keys);
        } catch (\Throwable $e) {
            return [0, 0];
        }

        if (!is_array($values)) {
            return [0, 0];
        }

        $globalVer = (int) ($values[0] ?? 0);
        $userVer = is_int($userId) ? (int) ($values[1] ?? 0) : 0;

        return [$globalVer, $userVer];
    }

    // ============================================================
    // ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
    // ============================================================

    private function normalizeFilters(array $filters): array
    {
        $filters = array_filter($filters, function ($value) {
            return $value !== null && $value !== '' && $value !== [];
        });

        if (!empty($filters['interests']) && is_array($filters['interests'])) {
            $filters['interests'] = array_filter(array_map('intval', $filters['interests']));
        }

        $scalarArrayFilters = [
            'body_type', 'eye_color', 'hair_color', 'relationship_status',
            'children_status', 'pets', 'housing', 'has_car',
            'education_level', 'income', 'smoking', 'alcohol', 'zodiac_sign',
        ];
        foreach ($scalarArrayFilters as $field) {
            if (!empty($filters[$field]) && is_array($filters[$field])) {
                $filters[$field] = array_filter(array_map('intval', $filters[$field]));
            }
        }

        $jsonArrayFilters = ['languages', 'sports', 'body_decorations'];
        foreach ($jsonArrayFilters as $field) {
            if (!empty($filters[$field]) && is_array($filters[$field])) {
                $filters[$field] = array_filter(array_map('intval', $filters[$field]));
            }
        }

        return $filters;
    }

    private function normalizeAgeRange(int $from, int $to): array
    {
        $min = config('search.age_min', 18);
        $max = config('search.age_max', 99);

        $from = max($min, min($max, $from));
        $to = max($min, min($max, $to));

        if ($from > $to) {
            return [$to, $from];
        }
        return [$from, $to];
    }

    public function normalizePerPage(?int $perPage): int
    {
        $default = config('search.per_page', 20);
        $max = config('search.max_per_page', 50);

        if ($perPage === null || $perPage < 1) {
            return $default;
        }

        return min($perPage, $max);
    }

    private function isTruthy(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function hasDistanceColumn(Builder $query): bool
    {
        $columns = $query->getQuery()->columns;
        if (empty($columns)) {
            return false;
        }
        foreach ($columns as $column) {
            $columnStr = is_string($column) ? $column : ($column->getValue() ?? '');
            if (str_contains($columnStr, 'distance')) {
                return true;
            }
        }
        return false;
    }

    public function buildCacheKey(?User $user, array $filters, int $page, int $perPage): string
    {
        $userId = $user?->id ?? 'guest';

        // Версии кэша: глобальная + личная (см. invalidateAllCache / invalidateUserCache).
        // Инвалидация = INCR версии: ключ «переезжает», а старое значение
        // доживает свой 60-секундный TTL в никуда (удалять ничего не нужно).
        [$globalVer, $userVer] = $this->readVersions($userId);

        $profileHash = 'none';
        if ($user && $user->profile) {
            $profileHash = md5(implode('|', [
                $user->profile->gender ?? '',
                $user->profile->birth_date ?? '',
                $user->profile->city_id ?? '',
                $user->profile->country_id ?? '',
            ]));
        }

        $preferencesHash = 'none';
        if ($user && $user->preferences) {
            $preferencesHash = md5(implode('|', [
                $user->preferences->preferred_gender ?? '',
                $user->preferences->preferred_age_min ?? '',
                $user->preferences->preferred_age_max ?? '',
                $user->preferences->preferred_distance_km ?? '',
                json_encode($user->preferences->search_filters ?? []),
            ]));
        }

        ksort($filters);
        $filtersHash = md5(json_encode(
            $filters,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return sprintf(
            '%s:g%d:u%s:v%d:p%s:pr%s:f%s:p%d:s%d',
            self::CACHE_PREFIX,
            $globalVer,
            $userId,
            $userVer,
            substr($profileHash, 0, 8),
            substr($preferencesHash, 0, 8),
            $filtersHash,
            $page,
            $perPage
        );
    }
}