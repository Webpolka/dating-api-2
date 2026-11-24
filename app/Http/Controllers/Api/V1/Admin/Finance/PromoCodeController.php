<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Actions\V1\Admin\Finance\ManagePromoCodeAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Finance\BulkGeneratePromoCodesRequest;
use App\Http\Requests\Api\V1\Admin\Finance\StorePromoCodeRequest;
use App\Http\Requests\Api\V1\Admin\Finance\UpdatePromoCodeRequest;
use App\Http\Resources\Api\V1\Admin\Finance\PromoCodeDetailResource;
use App\Http\Resources\Api\V1\Admin\Finance\PromoCodeResource;
use App\Http\Resources\Api\V1\Admin\Finance\PromoCodeUsageResource;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Управление промокодами (Admin API).
 *
 * Thin-controller: все мутации делегируют ManagePromoCodeAction.
 *
 * По образцу GiftController:
 *  - index: список с фильтрами (q, type, status=active/inactive/expired,
 *    scope=general/personal).
 *  - counts: счётчики для табов.
 *  - show: детальный просмотр с usages (пагинация) + aggregate stats.
 *  - store: создание одного (code опционален — если пустой, генерится).
 *  - update: редактирование (code/discount_type immutable).
 *  - destroy: удаление (или деактивация если уже использовали).
 *  - toggleStatus: вкл/выкл.
 *  - bulkGenerate: сгенерировать N кодов (для маркетинговой кампании).
 */
class PromoCodeController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $search      = (string) $request->string('q', '');
        $typeFilter  = (string) $request->string('type', 'all');
        $statusFilter = (string) $request->string('status', 'all');
        $scopeFilter = (string) $request->string('scope', 'all');
        $perPage     = min(max((int) $request->integer('per_page', 15), 10), 100);
        $page        = max((int) $request->integer('page', 1), 1);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId           = $search !== '' && ctype_digit($search);

        $promos = PromoCode::query()
            ->when($search !== '', function ($q) use ($search, $searchOperator, $isId) {
                $q->where(function ($q) use ($search, $searchOperator, $isId) {
                    $q->where('code', $searchOperator, "%{$search}%");
                    if ($isId) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($typeFilter !== 'all', fn($q) => $q->where('discount_type', $typeFilter))
            ->when($scopeFilter === 'general', fn($q) => $q->whereNull('user_id'))
            ->when($scopeFilter === 'personal', fn($q) => $q->whereNotNull('user_id'))
            ->when($statusFilter === 'active', function ($q) {
                // "Активные" = РЕАЛЬНО валидные для использования:
                //   is_active=true AND (expires_at IS NULL OR expires_at >= now)
                //   AND (max_uses IS NULL OR used_count < max_uses)
                // Если просто where('is_active', true) — в таб попадают коды
                // с прошедшим expires_at и исчерпанные — это баг (брат заметил).
                $q->where('is_active', true)
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
                    })
                    ->where(function ($q) {
                        $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses');
                    });
            })
            ->when($statusFilter === 'inactive', fn($q) => $q->where('is_active', false))
            ->when($statusFilter === 'expired', function ($q) {
                // Истёкшие — все с прошедшей датой, независимо от is_active.
                $q->whereNotNull('expires_at')->where('expires_at', '<', now());
            })
            ->when($statusFilter === 'fully_used', function ($q) {
                $q->whereNotNull('max_uses')->whereColumn('used_count', '>=', 'max_uses');
            })
            ->when($isId, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            // Сортировка по updated_at — после создания/редактирования/toggle/apply
            // промокод оказывается сверху (как users по updated_at).
            ->latest('updated_at')
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);

        // eager-load user для персональных кодов после пагинации
        // (только для текущей страницы — не грузит весь набор).
        if ($promos->contains(fn($p) => $p->user_id !== null)) {
            $promos->loadMissing(['user:id,name,email']);
        }

        return $this->paginated($promos, PromoCodeResource::class, [
            'counts' => $this->getCounts(),
        ]);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['stats' => $this->getCounts()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $promo = PromoCode::with(['user:id,name,email'])->find($id);
        if (!$promo) {
            return $this->notFound('Промокод');
        }

        // Usages: пагинация (15 на страницу) + aggregate stats.
        $usagePage = max((int) $request->integer('usage_page', 1), 1);
        $usagesPaginator = PromoCodeUsage::where('promo_code_id', $promo->id)
            ->with([
                'user' => fn($q) => $q->withTrashed()
                    ->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                    ->withAvatar(),                
                'transaction:id,amount,status,type,created_at,meta',
            ])
            ->latest('created_at')
            ->latest('id')
            ->paginate(15, ['*'], 'usage_page', $usagePage);

        // Aggregate stats (полные, по всем usages — не только текущая страница).
        $aggregate = PromoCodeUsage::where('promo_code_id', $promo->id)
            ->selectRaw('COUNT(*) as total_usages, COALESCE(SUM(applied_discount), 0) as total_discount_sum')
            ->first();

        // Подменяем relation на items текущей страницы (чтобы whenLoaded сработал).
        $promo->setRelation('usages', $usagesPaginator->getCollection());

        $detail = (new PromoCodeDetailResource($promo))
            ->additional([
                'total_usages'       => (int) ($aggregate->total_usages ?? 0),
                'total_discount_sum' => (float) ($aggregate->total_discount_sum ?? 0),
            ])
            ->resolve($request);

        return response()->json([
            'data'  => $detail,
            'meta'  => [
                'current_usage_page' => $usagesPaginator->currentPage(),
                'last_usage_page'    => $usagesPaginator->lastPage(),
                'per_usage_page'     => $usagesPaginator->perPage(),
                'total_usages_pages' => $usagesPaginator->total(),
            ],
        ]);
    }

    public function store(StorePromoCodeRequest $request, ManagePromoCodeAction $action): JsonResponse
    {
        try {
            $promo = $action->store($request->user(), $request->validated());
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                $code = $request->validated()['code'] ?? '';
                return $this->message(
                    "Промокод «{$code}» уже существует. Выберите другой или оставьте поле пустым для автогенерации.",
                    422,
                    ['errors' => ['code' => ["Код «{$code}» уже занят."]]],
                );
            }
            throw $e;
        }

        return $this->message('Промокод создан', 200, [
            'data' => new PromoCodeResource($promo),
        ]);
    }

    public function update(UpdatePromoCodeRequest $request, int $id, ManagePromoCodeAction $action): JsonResponse
    {
        $promo = PromoCode::find($id);
        if (!$promo) {
            return $this->notFound('Промокод');
        }

        $promo = $action->update($promo, $request->user(), $request->validated());

        return $this->message('Промокод обновлён', 200, [
            'data' => new PromoCodeResource($promo),
        ]);
    }

    public function destroy(Request $request, int $id, ManagePromoCodeAction $action): JsonResponse
    {
        $result = $action->destroy($id, $request->user());

        if ($result === null) {
            return $this->notFound('Промокод');
        }

        if ($result['deleted']) {
            return $this->message('Промокод удалён', 200, [
                'deleted'    => true,
                'used_count' => 0,
            ]);
        }

        return $this->message(
            "Промокод использовали {$result['used_count']} раз. Он деактивирован, но сохранён в БД для финансовой истории.",
            200,
            [
                'deleted'    => false,
                'used_count' => $result['used_count'],
            ],
        );
    }

    public function toggleStatus(Request $request, int $id, ManagePromoCodeAction $action): JsonResponse
    {
        $promo = PromoCode::find($id);
        if (!$promo) {
            return $this->notFound('Промокод');
        }

        $promo = $action->toggleStatus($promo, $request->user());

        return $this->message(
            $promo->is_active ? 'Промокод активирован' : 'Промокод деактивирован',
            200,
            ['is_active' => $promo->is_active],
        );
    }

    public function bulkGenerate(BulkGeneratePromoCodesRequest $request, ManagePromoCodeAction $action): JsonResponse
    {
        $validated = $request->validated();
        $requested = (int) $validated['count'];
        $created = $action->bulkGenerate($request->user(), $validated);

        if ($created === $requested) {
            return $this->message("Создано {$created} промокодов", 200, [
                'created'  => $created,
                'requested' => $requested,
            ]);
        }

        // Не все создались из-за коллизий — сообщаем честно.
        return $this->message(
            "Создано {$created} из {$requested} промокодов (остальные пропущены из-за коллизий кодов — повторите).",
            200,
            ['created' => $created, 'requested' => $requested, 'collisions' => $requested - $created],
        );
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        return Cache::remember('admin_promo_codes_counts', 60, function () {
            $stats = PromoCode::query()
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN is_active = false THEN 1 ELSE 0 END) as inactive,
                    SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < NOW() THEN 1 ELSE 0 END) as expired,
                    SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) as personal,
                    SUM(CASE WHEN max_uses IS NOT NULL AND used_count >= max_uses THEN 1 ELSE 0 END) as fully_used
                ")->first();

            $usageStats = PromoCodeUsage::query()
                ->selectRaw('COUNT(*) as total_usages, COALESCE(SUM(applied_discount), 0) as total_discount')
                ->first();

            return [
                'total'          => (int) ($stats->total ?? 0),
                'active'         => (int) ($stats->active ?? 0),
                'inactive'       => (int) ($stats->inactive ?? 0),
                'expired'        => (int) ($stats->expired ?? 0),
                'personal'       => (int) ($stats->personal ?? 0),
                'fully_used'     => (int) ($stats->fully_used ?? 0),
                'total_usages'   => (int) ($usageStats->total_usages ?? 0),
                'total_discount' => (float) ($usageStats->total_discount ?? 0),
            ];
        });
    }
}
