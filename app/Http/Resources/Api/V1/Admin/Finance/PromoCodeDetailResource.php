<?php

namespace App\Http\Resources\Api\V1\Admin\Finance;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Детальный промокод (GET /admin/promo-codes/{id}).
 *
 * PromoCodeResource + usages (с пагинацией, eager-loaded) + aggregate stats.
 */
class PromoCodeDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var \App\Models\PromoCode $promo */
        $promo = $this->resource;
 
        $base = (new PromoCodeResource($promo))->toArray($request);

        // usages — eager-loaded paginator (контроллер делает paginate → items).
        // В переданной модели usages — коллекция PromoCodeUsage (currentPage items).
        $usages = $this->whenLoaded('usages', fn () => $promo->usages
            ? PromoCodeUsageResource::collection($promo->usages)->resolve($request)
            : []);

        // aggregate stats по всем usages (контроллер грузит их отдельным запросом).
        $stats = $this->whenLoaded('usages', fn () => [
            'total_usages'       => $promo->usages->count() + (($this->additional['total_usages'] ?? 0) - $promo->usages->count() > 0 ? ($this->additional['total_usages'] ?? $promo->usages->count()) : $promo->usages->count()),
            'total_discount_sum' => (float) ($this->additional['total_discount_sum'] ?? $promo->usages->sum(fn($u) => (float) $u->applied_discount)),
        ], [
            'total_usages'       => $this->additional['total_usages'] ?? 0,
            'total_discount_sum' => (float) ($this->additional['total_discount_sum'] ?? 0),
        ]);

        return array_merge($base, [
            'updated_at' => $promo->updated_at?->toIso8601String(),
            'usages'     => $usages,
            'stats'      => $stats,
        ]);
    }
}
