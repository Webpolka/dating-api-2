<?php

namespace App\Http\Controllers\Api\V1\Admin\Main;

use App\Http\Requests\Api\V1\Admin\Main\UserDiariesRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserDiaryCommentsRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserPhotoCommentsRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserPhotosRequest;
use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use App\Models\Album;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryRubric;
use App\Models\Photo;
use App\Models\PhotoComment;
use Illuminate\Http\JsonResponse;

/**
 * Пер-юзер листинги: КОНТЕНТ (табы «Фото», «Комментарии к фото»,
 * «Дневники», «Комментарии к дневникам» страницы /users/{id}).
 *
 * ПАКЕТ 6: выделен из монолита UserListingsController (1477 строк).
 * URL и форматы ответов — 1:1 с прежним кодом.
 */
class UserContentListingsController extends UserTabListingController
{
    // ============================================
    // ФОТО АЛЬБОМЫ юзера (без модерации — группировка по альбомам)
    // ============================================

    /**
     * GET /api/admin/users/{user}/photos
     *
     * Composite-ответ — «фото-альбомы» юзера (зеркало того, что хотел блейд):
     * один альбом = одна карточка на фронте. Без модерации: статусы, reject_reason,
     * moderated_at, trashed НЕ отдаём (модерация живёт в глобальной очереди
     * /moderation/photos). withTrashed у альбомов — чтобы видеть удалённые
     * (на случай post-factum отчёта). Фото — только не-soft-deleted (в ленте
     * удалённые фото не показываем).
     *
     * Структура:
     *   - albums[] — список альбомов юзера, каждый с полным набором фото
     *     (is_primary DESC, position ASC — как Album::photos()). Без пагинации
     *     фото внутри альбома: один альбом = одна карточка, листать не нужно.
     *   - counts — {albums, photos_total, photos_primary, photos_intimate}.
     *
     * Фото без album_id (NULL) группируем в виртуальный «Без альбома» (id=null,
     * name='Без альбома') — не теряем их.
     *
     * Default-альбом — всегда первым (is_default=true → sort), затем по created_at.
     */
    public function photos(UserPhotosRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        // Реальные колонки photos (без accessor'ов URL — они в $appends модели).
        // Без модераторских полей (status/reject_reason/moderated_*) — таб без модерации.
        $photoColumns = [
            'id', 'user_id', 'album_id', 'type',
            'path_original', 'path_large', 'path_medium', 'path_thumb',
            'is_primary', 'is_intimate', 'position',
            'title', 'description',
            'created_at', 'updated_at',
        ];

        // Альбомы юзера (с soft-deleted — для post-factum). С жадной загрузкой фото
        // (только не-soft-deleted, primary DESC → position ASC, как Album::photos()).
        $albums = Album::withTrashed()
            ->where('user_id', $userId)
            ->with([
                'photos' => fn($q) => $q
                    ->whereNull('deleted_at')
                    ->select($photoColumns)
                    ->orderByDesc('is_primary')
                    ->orderBy('position')
                    ->orderByDesc('id'),
            ])
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // Фото без album_id (orphan) — в виртуальный «Без альбома».
        $orphanPhotos = Photo::query()
            ->where('user_id', $userId)
            ->whereNull('album_id')
            ->whereNull('deleted_at')
            ->select($photoColumns)
            ->orderByDesc('is_primary')
            ->orderBy('position')
            ->orderByDesc('id')
            ->get();

        // Собираем composite-список альбомов.
        $albumsPayload = $albums->map(fn(Album $a) => [
            'id'           => $a->id,
            'name'         => $a->name,
            'description'  => $a->description,
            'is_default'   => (bool) $a->is_default,
            'is_private'   => (bool) $a->is_private,
            'photos_count' => (int) $a->photos_count,
            'created_at'   => $a->created_at?->toIso8601String(),
            'deleted_at'   => $a->deleted_at?->toIso8601String(),
            'photos'       => $a->photos->map(fn(Photo $p) => $this->formatAlbumPhoto($p))->values(),
        ])->values();

        // Виртуальный «Без альбома» — последней карточкой, если есть orphan-фото.
        if ($orphanPhotos->isNotEmpty()) {
            $albumsPayload[] = [
                'id'           => null,
                'name'         => 'Без альбома',
                'description'  => null,
                'is_default'   => false,
                'is_private'   => false,
                'photos_count' => $orphanPhotos->count(),
                'created_at'   => null,
                'deleted_at'   => null,
                'photos'       => $orphanPhotos->map(fn(Photo $p) => $this->formatAlbumPhoto($p))->values(),
            ];
        }

        // Общие счётчики (для шапки таба на фронте).
        $allPhotos = $albums->flatMap->photos->merge($orphanPhotos);
        $counts = [
            'albums'         => $albumsPayload->count(),
            'photos_total'   => $allPhotos->count(),
            'photos_primary' => $allPhotos->where('is_primary', true)->count(),
            'photos_intimate'=> $allPhotos->where('is_intimate', true)->count(),
        ];

        return response()->json([
            'albums' => $albumsPayload,
            'counts' => $counts,
        ]);
    }

    /**
     * Формат одной фото-записи внутри альбома (без модераторских полей).
     *
     * URL accessor'ы (thumb_url/medium_url/large_url/original_url) — из Photo::$appends,
     * вычисляются в PHP из path_* колонок. Селектить их в SQL нельзя (не колонки БД).
     */
    private function formatAlbumPhoto(Photo $p): array
    {
        return [
            'id'          => $p->id,
            'type'        => $p->type,
            'is_primary'  => (bool) $p->is_primary,
            'is_intimate' => (bool) $p->is_intimate,
            'position'    => (int) $p->position,
            'title'       => $p->title,
            'description' => $p->description,
            'created_at'  => $p->created_at?->toIso8601String(),
            'thumb_url'   => $p->thumb_url,
            'medium_url'  => $p->medium_url,
            'large_url'   => $p->large_url,
            'original_url'=> $p->original_url,
        ];
    }

    // ============================================
    // МОДЕРАЦИЯ: комментарии к фото (made / received)
    // ============================================

    /**
     * GET /api/admin/users/{user}/photo-comments?made_page=1&received_page=2
     *
     * Две независимые колонки (зеркало reports/gifts):
     *   - made     — комментарии, НАПИСАННЫЕ пользователем (photo_comments.user_id = userId).
     *                Контекст: под каким фото оставлен + владелец этого фото (photo.user).
     *   - received — комментарии под ФОТО пользователя (photo.user_id = userId), автор —
     *                любой другой юзер. Контекст: автор комментария (user) + ссылка на фото.
     *
     * По 5 на колонку (как в старом blade-табе и в reports). Фильтра статусов нет —
     * обе колонки показывают всю историю «как есть» (включая карантин = soft-deleted),
     * статус виден бейджем в каждой строке. Масс-фильтрация живёт в глобальной очереди
     * /moderation/photo-comments.
     *
     * withTrashed() на PhotoComment — чтобы видеть карантин в обеих колонках
     * (админ должен видеть, что пользователь удалял, не уходя в глобальный раздел).
     */
    public function photoComments(UserPhotoCommentsRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $badgeUser = $this->badgeUser();

        // === made: комментарии, написанные самим пользователем ===
        // Контекст фото: превью (thumb/medium) + владелец фото, под которым
        // оставлен комментарий (photo.user) — чтобы видеть, ЧЬЕ фото комментировал юзер.
        $made = PhotoComment::withTrashed()
            ->with([
                'photo' => fn($q) => $q
                    ->withTrashed()
                    ->select(self::PHOTO_CONTEXT_COLUMNS)
                    ->with(['user' => $badgeUser]),
            ])
            ->where('user_id', $userId)
            ->latest('created_at')
            ->latest('id')
            ->paginate(5, ['*'], 'made_page', $request->madePage());

        // === received: комментарии под фото пользователя ===
        // Здесь автор комментария — ДРУГОЙ юзер (модератор хочет видеть, кто спамит
        // под фото этого пользователя). Контекст: автор (user) + ссылка на фото (id).
        // photo селектим минимально (id, user_id) — превью не нужно, колонка идёт
        // под «Автор», а не «Фото» (см. blade-эталон).
        $received = PhotoComment::withTrashed()
            ->with([
                'user'   => $badgeUser,
                'photo'  => fn($q) => $q->withTrashed()->select(['id', 'user_id']),
            ])
            ->whereHas('photo', fn($q) => $q->withTrashed()->where('user_id', $userId))
            ->latest('created_at')
            ->latest('id')
            ->paginate(5, ['*'], 'received_page', $request->receivedPage());

        return response()->json([
            'made'     => $this->paginatorToArray($made, fn(PhotoComment $c) => $this->formatPhotoComment($c)),
            'received' => $this->paginatorToArray($received, fn(PhotoComment $c) => $this->formatPhotoComment($c)),
            'counts'   => [
                'made'     => $made->total(),
                'received' => $received->total(),
            ],
        ]);
    }

    /**
     * Форматтер строки комментария — общий для обеих колонок.
     *
     * Контекстные поля (photo / user) добираются через with() в самом запросе
     * в зависимости от колонки:
     *   - made     → eager-load photo.user (владелец фото, под которым коммент).
     *   - received → eager-load user (автор комментария) + photo (только id).
     *
     * Поля photo/thumb_url/medium_url — accessor'ы Photo::$appends, в SQL мы
     * селектили только path_thumb/path_medium (см. PHOTO_CONTEXT_COLUMNS),
     * поэтому accessor безопасен (колонки загружены).
     */
    private function formatPhotoComment(PhotoComment $c): array
    {
        $p = $c->photo;

        return [
            'id'            => $c->id,
            'photo_id'      => $c->photo_id,
            'parent_id'     => $c->parent_id,
            'content'       => $c->content,
            'status'        => $c->status,
            'reject_reason' => $c->reject_reason,
            'moderated_by'  => $c->moderated_by,
            'moderated_at'  => $c->moderated_at?->toIso8601String(),
            'created_at'    => $c->created_at?->toIso8601String(),
            'updated_at'    => $c->updated_at?->toIso8601String(),
            'trashed'       => $c->trashed(),
            'is_pinned'     => (bool) $c->is_pinned,
            'likes_count'   => (int) $c->likes_count,
            'replies_count' => (int) $c->replies_count,
            // Автор комментария. В made-колонке это сам userId (фронт его не рисует),
            // в received — ДРУГОЙ юзер, подавший голос под нашим фото.
            'user' => $c->user ? AdminUserBadgeResource::nullable($c->user) : null,
            // Контекст фото: где оставлен комментарий. В made-колонке у photo есть
            // thumb_url/medium_url + владелец (photo.user); в received — только id/user_id
            // (превью там не рисуется, рисуется автор комментария).
            'photo' => $p ? [
                'id'         => $p->id,
                'user_id'    => $p->user_id,
                'status'     => $p->status,
                'is_primary' => (bool) $p->is_primary,
                'trashed'    => $p->trashed(),
                'thumb_url'  => $p->thumb_url,
                'medium_url' => $p->medium_url,
                'user'       => $p->user ? AdminUserBadgeResource::nullable($p->user) : null,
            ] : null,
        ];
    }

    // ============================================
    // МОДЕРАЦИЯ: дневники (автор = юзер)
    // ============================================

    /**
     * GET /api/admin/users/{user}/diaries?rubric=all&search=&page=2
     *
     * Дневники, написанные пользователем (author = user).
     * Всегда withTrashed — карантин виден бейджем строки (как в старом blade-табе),
     * отдельный фильтр по статусу не нужен: статус виден в каждой строке, а
     * масс-фильтрация живёт в глобальном разделе /moderation/diary.
     *
     * В ответе отдельным полем `rubrics` — список рубрик, в которых у юзера реально
     * есть посты (для кнопок фильтра на фронте). Личные рубрики (user_id != null)
     * идут раньше глобальных — PostgreSQL по умолчанию кидает NULL в конец, что
     * некорректно для UX.
     */
    public function diaries(UserDiariesRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $rubric = $request->rubric('all');
        $search = $request->search();
        $perPage = 10;

        $operator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        $query = Diary::withTrashed()
            ->with([
                'user' => $this->badgeUser(),
                'diaryRubric',
            ])
            ->where('user_id', $userId)
            ->when($search, function ($q) use ($operator, $search) {
                // Поиск по названию (ilike на pgsql) + по id, если запрос — число.
                $q->where(function ($q) use ($operator, $search) {
                    $q->where('title', $operator, "%{$search}%");
                    if (is_numeric($search)) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($rubric !== 'all', function ($q) use ($rubric) {
                if ($rubric === 'none') {
                    $q->whereNull('diary_rubric_id');
                } else {
                    $q->where('diary_rubric_id', $rubric);
                }
            })
            ->latest('created_at');

        $diaries = $query->paginate($perPage, ['*'], 'page', $request->page());

        // Рубрики, в которых у юзера реально есть посты — для кнопок фильтра.
        // Личные (user_id IS NOT NULL) раньше глобальных (NULL).
        $rubrics = DiaryRubric::whereHas('diaries', fn($q) => $q->where('user_id', $userId))
            ->orderByRaw('user_id IS NULL DESC')
            ->orderBy('name')
            ->get(['id', 'user_id', 'name', 'is_active']);

        return response()->json(
            $this->paginatorToArray($diaries, fn(Diary $d) => $this->formatDiary($d)) + [
                'rubrics' => $rubrics->map(fn(DiaryRubric $r) => [
                    'id'          => $r->id,
                    'name'        => $r->name,
                    'is_active'   => (bool) $r->is_active,
                    'is_personal' => $r->user_id !== null,
                    'user_id'     => $r->user_id,
                ])->values()->toArray(),
                'rubric' => $rubric,
                'search' => $search,
            ]
        );
    }

    /**
     * Копия DiaryController::formatDiary() (не detailed — body не нужен
     * в списке; полный текст грузится отдельным эндпоинтом /diary/{id}).
     */
    private function formatDiary(Diary $d): array
    {
        return [
            'id'                 => $d->id,
            'title'              => $d->title,
            'status'             => $d->status,
            'reject_reason'      => $d->reject_reason,
            'moderated_by'       => $d->moderated_by,
            'moderated_at'       => $d->moderated_at?->toIso8601String(),
            'published_at'       => $d->published_at?->toIso8601String(),
            'created_at'         => $d->created_at?->toIso8601String(),
            'updated_at'         => $d->updated_at?->toIso8601String(),
            'trashed'            => $d->trashed(),
            'is_comments_enabled' => (bool) $d->is_comments_enabled,
            'views_count'        => (int) $d->views_count,
            'comments_count'     => (int) $d->comments_count,
            'likes_count'        => (int) $d->likes_count,
            'user'               => $d->user ? AdminUserBadgeResource::nullable($d->user, withRole: true) : null,
            'diary_rubric'       => $d->diaryRubric ? [
                'id'          => $d->diaryRubric->id,
                'name'        => $d->diaryRubric->name,
                'is_active'   => (bool) $d->diaryRubric->is_active,
                'is_personal' => $d->diaryRubric->user_id !== null,
                'user_id'     => $d->diaryRubric->user_id,
            ] : null,
        ];
    }

    // ============================================
    // МОДЕРАЦИЯ: комментарии к дневникам (автор = юзер)
    // ============================================

    /**
     * GET /api/admin/users/{user}/diary-comments?made_page=1&received_page=2
     *
     * Две независимые колонки (зеркало photoComments / reports / gifts):
     *   - made     — комментарии, НАПИСАННЫЕ пользователем (diary_comments.user_id = userId).
     *                Контекст: под какой записью оставлен (diary) + автор этой записи (diary.user).
     *   - received — комментарии под ЗАПИСЯМИ пользователя (diary.user_id = userId),
     *                автор комментария — любой другой юзер. Контекст: автор (user) + запись (diary).
     *
     * По 5 на колонку (как photoComments). withTrashed() — чтобы видеть карантин.
     * Фильтра статусов нет: обе колонки показывают всю историю «как есть»,
     * статус виден бейджем в каждой строке.
     */
    public function diaryComments(UserDiaryCommentsRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $badgeUser = $this->badgeUser();

        // === made: комментарии, написанные самим пользователем ===
        // Контекст: запись (diary) + автор записи (diary.user) — чтобы видеть,
        // под чьим постом оставил комментарий юзер.
        $made = DiaryComment::withTrashed()
            ->with([
                'diary' => fn($q) => $q->withTrashed()
                    ->select(['id', 'user_id', 'title', 'status', 'deleted_at'])
                    ->with(['user' => $badgeUser]),
            ])
            ->where('user_id', $userId)
            ->latest('created_at')
            ->latest('id')
            ->paginate(5, ['*'], 'made_page', $request->madePage());

        // === received: комментарии под записями пользователя ===
        // Автор комментария — ДРУГОЙ юзер. Контекст: автор (user) + запись (diary,
        // только id/title — превью не нужно, колонка про автора).
        $received = DiaryComment::withTrashed()
            ->with([
                'user'  => $badgeUser,
                'diary' => fn($q) => $q->withTrashed()->select(['id', 'user_id', 'title', 'deleted_at']),
            ])
            ->whereHas('diary', fn($q) => $q->withTrashed()->where('user_id', $userId))
            ->latest('created_at')
            ->latest('id')
            ->paginate(5, ['*'], 'received_page', $request->receivedPage());

        return response()->json([
            'made'     => $this->paginatorToArray($made, fn(DiaryComment $c) => $this->formatDiaryComment($c)),
            'received' => $this->paginatorToArray($received, fn(DiaryComment $c) => $this->formatDiaryComment($c)),
            'counts'   => [
                'made'     => $made->total(),
                'received' => $received->total(),
            ],
        ]);
    }

    /**
     * Форматтер строки комментария — общий для обеих колонок.
     *
     * Контекстные поля (diary / user) добираются через with() в самом запросе:
     *   - made     → diary.user (автор записи, под которой коммент).
     *   - received → user (автор комментария) + diary (id/title).
     *
     * `user` (автор комментария) добавлен — в received-колонке это ДРУГОЙ юзер,
     * в made — сам userId (фронт его не рисует, но поле приходит).
     */
    private function formatDiaryComment(DiaryComment $c): array
    {
        $d = $c->diary;

        return [
            'id'            => $c->id,
            'diary_id'      => $c->diary_id,
            'parent_id'     => $c->parent_id,
            'content'       => $c->content,
            'status'        => $c->status,
            'reject_reason' => $c->reject_reason,
            'moderated_by'  => $c->moderated_by,
            'moderated_at'  => $c->moderated_at?->toIso8601String(),
            'created_at'    => $c->created_at?->toIso8601String(),
            'updated_at'    => $c->updated_at?->toIso8601String(),
            'trashed'       => $c->trashed(),
            'likes_count'   => (int) $c->likes_count,
            'replies_count' => (int) $c->replies_count,
            // Автор комментария. В made-колонке это сам userId (фронт не рисует),
            // в received — ДРУГОЙ юзер, написавший под записью нашего юзера.
            'user' => $c->user ? AdminUserBadgeResource::nullable($c->user) : null,
            // Контекст: под какой записью оставлен комментарий.
            'diary' => $d ? [
                'id'      => $d->id,
                'title'   => $d->title,
                'status'  => $d->status,
                'trashed' => $d->trashed(),
                'user'    => $d->user ? AdminUserBadgeResource::nullable($d->user) : null,
            ] : null,
        ];
    }
}
