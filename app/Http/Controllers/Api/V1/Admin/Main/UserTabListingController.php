<?php

namespace App\Http\Controllers\Api\V1\Admin\Main;

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Базовый класс пер-юзер листингов (табов страницы /users/{id}).
 *
 * ПАКЕТ 6 (разбор UserListingsController, рекомендация №4 аудита Шага 1):
 * монолит в 1477 строк разделён на 4 доменных контроллера —
 * UserContentListings / UserActivityListings / UserFinanceListings /
 * UserAuditListings. URL всех эндпоинтов и форматы ответов сохранены 1:1
 * (проверяется golden-контрактом tests/Feature/Admin/UserListingsContractTest.php,
 * который гоняется на staging против СТАРОГО и НОВОГО кода — форматы должны
 * совпасть побайтово).
 *
 * ОБЩИЕ ПРАВИЛА (зеркалируют UserController):
 *
 * 1. 404-гард: юзер ищется с withTrashed() — страница деактивированного
 *    юзера должна работать (восстановление, просмотр истории).
 *
 * 2. Форма ответа — «плоский» Laravel-пагинатор:
 *    {data: [...], total, current_page, last_page, per_page, from, to}
 *    + опциональные counts (для статус-табов) и status (эхо фильтра).
 *    ВАЖНО: это НЕ формат paginated() из AdminController (там meta-обёртка).
 *    Переход на paginated() ломал бы админ-фронт — осознанное отклонение
 *    от буквы рекомендации №4 аудита (аудит предлагал «перейти на хелпер
 *    paginated()», но формат пагинации пер-юзер табов исторически плоский,
 *    и фронт на нём построен).
 *
 * 3. ACCESSOR-БЕЗОПАСНОСТЬ (главный класс багов этого проекта на
 *    PostgreSQL): в ->select() ЗАПРЕЩЕНО указывать accessor'ы из
 *    $appends моделей — только реальные колонки БД:
 *      - User: avatar_url, is_online, has_active_premium/vip — accessor'ы
 *      - Photo: thumb_url, medium_url, large_url, original_url — accessor'ы
 *    Иначе PostgreSQL падает с `column "thumb_url" does not exist`.
 *
 * 4. Мутации НЕ живут здесь: табы только читают. Действия идут через
 *    глобальные разделы модерации (POST /admin/photos/{id}/* и т.д.).
 *
 * 5. Комментарии по-русски, с объяснением «почему» — стандарт проекта.
 */
abstract class UserTabListingController extends AdminController
{
    /**
     * Реальные колонки photos для avatar-контекста (без accessor'ов!).
     * Уже есть в UserController::PHOTO_AVATAR_COLUMNS — здесь своя копия
     * с path_medium (для превью фото в списках комментариев).
     */
    protected const PHOTO_CONTEXT_COLUMNS = [
        'id',
        'user_id',
        'is_primary',
        'status',
        'path_thumb',
        'path_medium',
        'deleted_at',
    ];

    /**
     * Реальные колонки users для AdminUserBadgeResource (+ role для withRole).
     * avatar_url / is_online / has_active_* — accessor'ы, НЕ селектим.
     */
    protected const USER_BADGE_COLUMNS = [
        'id',
        'name',
        'email',
        'role',
        'status',
        'deleted_at',
        'is_verified',
        'premium_expires_at',
        'vip_expires_at',
        'last_seen',
    ];

    // ============================================
    // ИНСТРУМЕНТЫ
    // ============================================

    /**
     * 404-гард юзера. null = юзер найден, можно работать.
     * JsonResponse = вернуть его сразу из метода.
     */
    protected function userNotFoundResponse(int $userId): ?JsonResponse
    {
        if (User::withTrashed()->find($userId)) {
            return null;
        }
        return response()->json(['message' => 'Пользователь не найден'], 404);
    }

    /**
     * Eager-load юзера с аватаркой для AdminUserBadgeResource.
     * Селект — только реальные колонки (см. USER_BADGE_COLUMNS).
     */
    protected function badgeUser(): \Closure
    {
        return fn($q) => $q
            ->withTrashed()
            ->select(self::USER_BADGE_COLUMNS)
            ->with([
                'photos' => fn($sq) => $sq
                    ->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb'])
                    ->orderByDesc('is_primary')
                    ->orderByDesc('id'),
                    // FIX: убран ->limit(1) — ловушка constrained eager load
            ]);
    }

    /**
     * Универсальный конвертер paginator → плоский JSON.
     * Структура идентична UserController::reportPaginatorToArray().
     *
     * @param \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator
     * @param callable $formatter fn($model) => array
     */
    protected function paginatorToArray($paginator, callable $formatter): array
    {
        return [
            'data'         => $paginator->getCollection()->map($formatter)->values(),
            'total'        => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'from'         => $paginator->firstItem(),
            'to'           => $paginator->lastItem(),
        ];
    }
}
