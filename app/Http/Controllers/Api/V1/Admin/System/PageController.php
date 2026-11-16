<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\System\PageBulkActionRequest;
use App\Http\Requests\Api\V1\Admin\System\StorePageRequest;
use App\Http\Requests\Api\V1\Admin\System\UpdatePageRequest;
use App\Http\Resources\Api\V1\Admin\System\PageResource;
use App\Models\AdminLog;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * CMS — статические страницы сайта.
 *
 * body чистится через Purifier (XSS protection). После save возвращаем
 * очищенный body — чтобы фронт обновил редактор реактивно и юзер увидел,
 * что Purifier вырезал запрещённые теги.
 *
 * Cache: admin_pages_counts (60 сек). Инвалидация через clearCountsCache()
 * после каждого mutation.
 *
 * ПАКЕТ 5 (рекомендация №6): инлайн-валидация (Validator::make) переведена
 * на StorePageRequest / UpdatePageRequest / PageBulkActionRequest.
 * Формат 422 {"errors": {...}} сохранён через AdminJsonRequest.
 */
class PageController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $search  = (string) $request->input('search', '');
        $status  = (string) $request->input('status', 'all');
        $page    = max((int) $request->input('page', 1), 1);
        $perPage = min(max((int) $request->input('per_page', 15), 10), 100);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId = !empty($search) && ctype_digit($search);

        $pages = Page::query()
            ->when($search, function ($q) use ($searchOperator, $isId, $search) {
                $q->where(function ($q2) use ($searchOperator, $isId, $search) {
                    $q2->where('title', $searchOperator, "%{$search}%")
                        ->orWhere('slug', $searchOperator, "%{$search}%");
                    if ($isId) {
                        $q2->orWhere('id', (int) $search);
                    }
                });

                if ($isId) {
                    $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
                }
            })
            ->when($status === 'active', fn($q) => $q->where('is_active', true))
            ->when($status === 'draft',  fn($q) => $q->where('is_active', false))
            ->latest('updated_at')
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'pages'  => PageResource::collection($pages->getCollection())->resolve(request()),
            'total'  => $pages->total(),
            'current_page' => $pages->currentPage(),
            'last_page'    => $pages->lastPage(),
            'per_page'     => $pages->perPage(),
            'counts' => $this->getCounts(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        return response()->json([
            'data' => (new PageResource($page))->withDetails()->resolve(request()),
        ]);
    }

    public function store(StorePageRequest $request): JsonResponse
    {
        $admin = $request->user();
        $data = $request->validated();

        // Purifier для body — XSS protection.
        if (!empty($data['body'])) {
            $data['body'] = clean($data['body']);
        }

        $page = Page::create($data);

        AdminLog::record('page.create', $page, $admin, null, [
            'status'  => 'created',
            'context' => [
                'page_id'  => $page->id,
                'title'    => $page->title,
                'slug'     => $page->slug,
                'admin_id' => $admin->id,
            ],
        ]);

        $this->clearCountsCache();

        return response()->json([
            'message' => 'Страница создана!',
            'page'    => ['id' => $page->id],
            // Возвращаем очищенный body — чтобы фронт обновил редактор
            // и юзер увидел, что Purifier вырезал запрещённые теги.
            'body'    => $page->body,
        ], 201);
    }

    public function update(UpdatePageRequest $request, int $id): JsonResponse
    {
        $page = Page::findOrFail($id);

        $admin = $request->user();
        $data = $request->validated();

        // Purifier для body.
        if (!empty($data['body'])) {
            $data['body'] = clean($data['body']);
        }

        $before = [
            'title'     => $page->getOriginal('title'),
            'slug'      => $page->getOriginal('slug'),
            'is_active' => $page->getOriginal('is_active'),
        ];

        $page->update($data);

        AdminLog::record('page.update', $page, $admin, $before, [
            'title'     => $page->title,
            'slug'      => $page->slug,
            'is_active' => $page->is_active,
            'context' => [
                'page_id'  => $page->id,
                'title'    => $page->title,
                'slug'     => $page->slug,
                'admin_id' => $admin->id,
            ],
        ]);

        $this->clearCountsCache();

        return response()->json([
            'message' => 'Страница обновлена!',
            // Возвращаем очищенный body — фронт обновит редактор реактивно.
            'body'    => $page->body,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $admin = $request->user();

        $before = ['title' => $page->getOriginal('title'), 'slug' => $page->getOriginal('slug')];

        AdminLog::record('page.delete', $page, $admin, $before, [
            'status'      => 'destroyed',
            'deleted_by'  => $admin->id,
            'context' => [
                'page_id'  => $page->id,
                'title'    => $page->title,
                'slug'     => $page->slug,
                'admin_id' => $admin->id,
            ],
        ]);

        $page->delete();

        $this->clearCountsCache();

        return $this->message('Страница удалена');
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $admin = $request->user();

        $before = ['is_active' => $page->getOriginal('is_active')];

        $page->is_active = !$page->is_active;
        $page->save();

        AdminLog::record('page.update', $page, $admin, $before, [
            'is_active'  => $page->is_active,
            'toggled_by' => $admin->id,
            'context' => [
                'page_id'  => $page->id,
                'title'    => $page->title,
                'slug'     => $page->slug,
                'admin_id' => $admin->id,
            ],
        ]);

        $this->clearCountsCache();

        return $this->message(
            $page->is_active ? 'Страница опубликована' : 'Страница снята с публикации',
            200,
            ['is_active' => $page->is_active],
        );
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $admin = $request->user();

        $newSlug = $page->slug . '-copy-' . time();
        $newPage = Page::create([
            'title'            => $page->title . ' (Копия)',
            'meta_title'       => $page->meta_title,
            'slug'             => $newSlug,
            'body'             => $page->body,
            'meta_description' => $page->meta_description,
            'is_active'        => false,
        ]);

        AdminLog::record('page.create', $newPage, $admin, null, [
            'status'  => 'created',
            'context' => [
                'source_page_id' => $page->id,
                'new_page_id'    => $newPage->id,
                'title'          => $newPage->title,
                'slug'           => $newPage->slug,
                'admin_id'       => $admin->id,
            ],
        ]);

        $this->clearCountsCache();

        return $this->message('Страница продублирована');
    }

    public function bulkAction(PageBulkActionRequest $request): JsonResponse
    {
        $admin = $request->user();
        $ids = $request->validated()['ids'];
        $action = $request->validated()['action'];

        if ($action === 'delete') {
            $affectedCount = Page::whereIn('id', $ids)->delete();

            if ($affectedCount > 0) {
                AdminLog::record('page.bulk_delete', null, $admin, null, [
                    'count'      => $affectedCount,
                    'sample_ids' => array_slice($ids, 0, 100),
                ]);
            }
        } else {
            $isActive = ($action === 'activate');
            $affectedCount = Page::whereIn('id', $ids)
                ->where('is_active', '!=', $isActive)
                ->update(['is_active' => $isActive]);

            if ($affectedCount > 0) {
                AdminLog::record('page.bulk_update', null, $admin, null, [
                    'action'     => $action,
                    'count'      => $affectedCount,
                    'sample_ids' => array_slice($ids, 0, 100),
                ]);
            }
        }

        $this->clearCountsCache();

        $messages = [
            'delete'   => "Удалено страниц: {$affectedCount}",
            'activate' => "Опубликовано страниц: {$affectedCount}",
            'draft'    => "Снято с публикации: {$affectedCount}",
        ];

        return $this->message($messages[$action] ?? 'Действие выполнено');
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        return Cache::remember('admin_pages_counts', 60, function () {
            $stats = Page::query()
                ->selectRaw("COUNT(*) as total")
                ->selectRaw("SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active")
                ->first();

            $total = (int) ($stats?->total ?? 0);
            $active = (int) ($stats?->active ?? 0);

            return [
                'all'    => $total,
                'active' => $active,
                'draft'  => $total - $active,
            ];
        });
    }

    private function clearCountsCache(): void
    {
        Cache::forget('admin_pages_counts');
    }
} 