<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Web\AttachInterestRequest;
use App\Http\Requests\Api\V1\Web\ProposeCustomInterestRequest;
use App\Models\Interest;
use App\Models\InterestCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ============================================================
 * InterestController — интересы пользователя и справочник
 * ============================================================
 *
 * Эндпоинты:
 *   GET    /api/interests            — справочник: категории + интересы (для multi-select)
 *   GET    /api/user/interests       — свои интересы (approved + pending)
 *   POST   /api/user/interests       — добавить интерес из справочника
 *   DELETE /api/user/interests/{id}  — убрать интерес у себя
 *   POST   /api/user/interests/custom — предложить свой интерес по имени
 *
 * Примечание по архитектуре: слой модерации интересов отсутствует
 * (справочник администрируется, см. Interest). Контракт pending
 * сохранён для фронтенда, но всегда пуст.
 */
class InterestController extends Controller
{
    /** Ключ кэша справочника (валидация — в boot() моделей Interest/InterestCategory). */
    private const INDEX_CACHE_KEY = 'interests:web:index';
    private const INDEX_CACHE_TTL = 600;

    /**
     * GET /api/interests
     *
     * Список всех интересов, сгруппированных по категориям.
     * Используется в multi-select на странице поиска.
     *
     * Справочник меняется редко — кэшируем на 10 минут,
     * чтобы не дёргать БД при каждом открытии формы.
     */
    public function index(): JsonResponse
    {
        $categories = Cache::remember(
            self::INDEX_CACHE_KEY,
            self::INDEX_CACHE_TTL,
            fn () => InterestCategory::with(['interests' => function ($q) {
                $q->select('id', 'name', 'slug', 'category_id', 'users_count', 'search_boost')
                    ->orderByDesc('search_boost')
                    ->orderBy('name');
            }])
                ->select('id', 'name', 'slug', 'icon')
                ->ordered()
                ->whereHas('interests')
                ->get()
        );

        return response()->json([
            'categories' => $categories,
        ]);
    }

    /**
     * GET /api/user/interests
     *
     * Свои интересы: approved (выбранные из справочника) + pending (на модерации).
     */
    public function my(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->load([
            'interests' => fn ($q) => $q->select('interests.id', 'interests.name', 'interests.slug', 'interests.category_id')
                ->orderByPivot('created_at', 'asc'),
            'interests.category:id,name,slug',
        ]);

        return response()->json([
            'approved' => $user->interests->map(fn ($interest) => [
                'id'       => $interest->id,
                'name'     => $interest->name,
                'category' => $interest->category ? [
                    'id'   => $interest->category->id,
                    'name' => $interest->category->name,
                ] : null,
            ])->values(),
            // Модерации интересов нет — pending всегда пуст.
            // Контракт оставлен для совместимости с фронтендом.
            'pending'  => [],
        ]);
    }

    /**
     * POST /api/user/interests
     * Body: { interest_id: int }
     *
     * Идемпотентно: повторный attach того же интереса — не ошибка.
     * users_count обновляется триггером user_interests_update_count.
     */
    public function attach(AttachInterestRequest $request): JsonResponse
    {
        $user = $request->user();
        $interestId = (int) $request->validated('interest_id');

        try {
            $interest = Interest::find($interestId);

            if (!$interest) {
                // Редкая гонка: интерес удалили между валидацией и запросом.
                return response()->json([
                    'success' => false,
                    'message' => 'Такой интерес не существует.',
                ], 404);
            }

            $user->interests()->syncWithoutDetaching([$interestId]);

            return response()->json([
                'success'  => true,
                'message'  => "Интерес «{$interest->name}» добавлен.",
                'interest' => ['id' => $interest->id, 'name' => $interest->name],
            ]);
        } catch (Throwable $e) {
            Log::error('InterestController: attach failed', [
                'user_id'     => $user->id,
                'interest_id' => $interestId,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Не удалось добавить интерес. Попробуйте позже.',
            ], 500);
        }
    }

    /**
     * DELETE /api/user/interests/{interest}
     *
     * Убирает интерес у себя (запись в pivot удаляется, сам справочник не трогаем).
     */
    public function detach(Request $request, Interest $interest): JsonResponse
    {
        $user = $request->user();

        try {
            $user->removeInterest($interest->id);

            return response()->json([
                'success' => true,
                'message' => "Интерес «{$interest->name}» удалён.",
            ]);
        } catch (Throwable $e) {
            Log::error('InterestController: detach failed', [
                'user_id'     => $user->id,
                'interest_id' => $interest->id,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Не удалось удалить интерес. Попробуйте позже.',
            ], 500);
        }
    }

    /**
     * POST /api/user/interests/custom
     * Body: { name: string }
     *
     * - Имя уже есть в справочнике (citext — сравнение без учёта регистра) → attach.
     * - Имени нет → создаём интерес и attach.
     *
     * TODO: когда появится слой модерации (таблица user_pending_interests) —
     * перевести создание нового интереса на pending-флоу с уведомлением админу.
     */
    public function proposeCustom(ProposeCustomInterestRequest $request): JsonResponse
    {
        $user = $request->user();
        $name = trim($request->validated('name'));

        try {
            $existing = Interest::where('name', $name)->first();

            if ($existing) {
                $user->interests()->syncWithoutDetaching([$existing->id]);

                return response()->json([
                    'success'  => true,
                    'message'  => "Интерес «{$existing->name}» уже существует — добавлен в ваш профиль.",
                    'interest' => ['id' => $existing->id, 'name' => $existing->name],
                    'status'   => 'attached_existing',
                ]);
            }

            $interest = Interest::findOrCreate($name);
            $user->interests()->syncWithoutDetaching([$interest->id]);

            return response()->json([
                'success'  => true,
                'message'  => "Интерес «{$interest->name}» создан и добавлен в ваш профиль.",
                'interest' => ['id' => $interest->id, 'name' => $interest->name],
                'status'   => 'created',
            ], 201);
        } catch (Throwable $e) {
            Log::error('InterestController: proposeCustom failed', [
                'user_id' => $user->id,
                'name'    => $name,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Не удалось добавить интерес. Попробуйте позже.',
            ], 500);
        }
    }
}
