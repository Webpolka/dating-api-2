<?php

namespace App\Http\Resources\Api\V1\Admin\Finance;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Одно использование промокода (для usage-list в деталях кода).
 *
 * user — badge с аватаром/статусом.
 * transaction — null-safe (для premium/vip redeem без транзакции — null).
 */
class PromoCodeUsageResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var \App\Models\PromoCodeUsage $usage */
        $usage = $this->resource;

        return [
            'id'               => $usage->id,
            'applied_discount' => (float) $usage->applied_discount,
            'created_at'       => $usage->created_at?->toIso8601String(),
            'user'             => $this->whenLoaded('user', fn () => $usage->user
                ? AdminUserBadgeResource::nullable($usage->user, withRole: true)
                : null),
            'transaction'      => $this->whenLoaded('transaction', fn () => $usage->transaction ? [
                'id'         => $usage->transaction->id,
                'amount'     => (float) $usage->transaction->amount,
                'status'     => $usage->transaction->status,
                'type'       => $usage->transaction->type,
                'created_at' => $usage->transaction->created_at?->toIso8601String(),
            ] : null),
        ];
    }
}
