<?php

namespace App\Http\Resources\Api\V1\Admin\Moderation;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Заявка верификации для grid-режима (история, поиск, approved/rejected)
 * и для инлайн-вставки (если понадобится в других Resource).
 *
 * Структура — по образцу PhotoResource: null-safe связи через whenLoaded,
 * user — через AdminUserBadgeResource (короткий badge).
 */
class VerificationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'user_id'       => $this->user_id,
            'photo_id'      => $this->photo_id,
            'code'          => $this->code,
            'status'        => $this->status,
            'reject_reason' => $this->reject_reason,
            'moderated_at'  => $this->moderated_at?->toIso8601String(),
            'created_at'    => $this->created_at?->toIso8601String(),
            'updated_at'    => $this->updated_at?->toIso8601String(),
            // photo — null-safe (мог быть удалён из photos через nullOnDelete).
            // Включаем urls (thumb/medium/large/original) и status — модератору важно
            // видеть, было ли фото в photos moderation тронуто.
            'photo'         => $this->whenLoaded('photo', fn () => $this->photo ? [
                'id'           => $this->photo->id,
                'status'       => $this->photo->status,
                'is_primary'   => (bool) $this->photo->is_primary,
                'type'         => $this->photo->type,
                'thumb_url'    => $this->photo->thumb_url,
                'medium_url'   => $this->photo->medium_url,
                'large_url'    => $this->photo->large_url,
                'original_url' => $this->photo->original_url,
            ] : null),
            // moderator — null-safe (nullOnDelete). Короткий badge.
            'moderator'     => $this->whenLoaded('moderator', fn () => $this->moderator
                ? AdminUserBadgeResource::nullable($this->moderator, withRole: true)
                : null),
            // user — null-safe. withRole=true — в модерации важно видеть role (staff/user).
            'user'          => $this->whenLoaded('user', fn () => $this->user
                ? AdminUserBadgeResource::nullable($this->user, withRole: true)
                : null),
        ];
    }
}
