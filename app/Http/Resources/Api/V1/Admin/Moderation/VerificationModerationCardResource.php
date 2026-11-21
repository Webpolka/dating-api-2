<?php

namespace App\Http\Resources\Api\V1\Admin\Moderation;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Карточка pending-заявки для очереди модерации (queue mode).
 *
 * Используется в VerificationController::index() когда status=pending и нет поиска.
 *
 * Структура: заявка + user badge + photo (для предпросмотра фото-с-кодом).
 * В отличие от PhotoModerationCardResource, нет группировки по юзеру
 * (UNIQUE partial index гарантирует один pending на юзера).
 *
 * ВАЖНО: user_id возвращаем явным полем (не только через nested user.id) —
 * RejectModal на фронте использует именно verification.user_id для
 * отображения в карточке подтверждения.
 */
class VerificationModerationCardResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'user_id'       => $this->user_id,
            'photo_id'      => $this->photo_id,
            'code'          => $this->code,
            'status'        => $this->status,
            'created_at'    => $this->created_at?->toIso8601String(),
            // user — с ролью/статусом/значками (модератору важен контекст).
            'user'          => $this->whenLoaded('user', fn () => $this->user
                ? AdminUserBadgeResource::nullable($this->user, withRole: true)
                : null),
            // photo — главное (фото с кодом-жестом, которое модератор смотрит).
            'photo'         => $this->whenLoaded('photo', fn () => $this->photo ? [
                'id'           => $this->photo->id,
                'status'       => $this->photo->status,
                'thumb_url'    => $this->photo->thumb_url,
                'medium_url'   => $this->photo->medium_url,
                'large_url'    => $this->photo->large_url,
                'original_url' => $this->photo->original_url,
            ] : null),
        ];
    }
}
