<?php

namespace App\Http\Resources\Api\V1\Admin\Finance;

use App\Enums\V1\PromoCodeType;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Карточка промокода в списке (/admin/promo-codes).
 *
 * Содержит всё для UI карточки + progress (used_count/max_uses).
 * user_loaded — null-safe через whenLoaded (персональный промокод → юзер).
 */
class PromoCodeResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var \App\Models\PromoCode $promo */
        $promo = $this->resource;

        $type = PromoCodeType::tryFrom($promo->discount_type);

        return [
            'id'              => $promo->id,
            'code'            => $promo->code,
            'discount_type'   => $promo->discount_type,
            'discount_type_label' => PromoCodeType::labelFor($promo->discount_type),
            'discount_value'  => (float) $promo->discount_value,
            'duration_days'   => $promo->duration_days,
            'max_uses'        => $promo->max_uses,
            'used_count'      => $promo->used_count,
            'remaining_uses'  => $promo->remainingUses(),
            'expires_at'      => $promo->expires_at?->toIso8601String(),
            'is_active'       => $promo->is_active,
            'is_expired'      => $promo->isExpired(),
            'is_personal'     => $promo->isPersonal(),
            'is_fully_used'   => $promo->isFullyUsed(),
            'gives_free_days' => $type?->givesFreeDays() ?? false,
            'created_at'      => $promo->created_at?->toIso8601String(),
            // Персональный юзер — null-safe (для общих кодов null).
            'user'            => $this->whenLoaded('user', fn () => $promo->user
                ? [
                    'id'     => $promo->user->id,
                    'name'   => $promo->user->name,
                    'email'  => $promo->user->email,
                    'avatar_url' => $promo->user->avatar_url,
                ]
                : null),
        ];
    }
}
