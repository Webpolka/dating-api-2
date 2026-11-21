<?php

namespace App\Http\Resources\Api\V1\Web;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Текущий статус верификации юзера (GET /api/verification/status).
 *
 * Возвращает:
 *  - is_verified: bool — глобальный статус (была ли хотя бы одна approved заявка).
 *  - active: null | { id, status, code?, photo?, created_at }
 *    активная (pending) заявка, если есть.
 *
 * Если active = null и is_verified = false — юзер ещё не подавал заявку
 * или все предыдущие отклонены. Фронт показывает кнопку "Пройти верификацию".
 *
 * Если active != null — фронт показывает статус pending и код (если юзер
 * его забыл — может перегенерить, но код в Redis уже не живой после submit).
 *
 * last_decision — последняя resolved заявка (approved/rejected) для контекста
 * "ваша предыдущая заявка была отклонена по причине X".
 */
class VerificationStatusResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var \App\Models\User $user */
        $user = $this->resource;

        $active = $user->verifications()
            ->where('status', \App\Models\Verification::STATUS_PENDING)
            ->latest('id')
            ->first();

        $lastDecision = $user->verifications()
            ->whereIn('status', [\App\Models\Verification::STATUS_APPROVED, \App\Models\Verification::STATUS_REJECTED])
            ->latest('id')
            ->first();

        return [
            'is_verified'   => (bool) $user->is_verified,
            'active'        => $active ? [
                'id'         => $active->id,
                'status'     => $active->status,
                'code'       => $active->code,
                'created_at' => $active->created_at?->toIso8601String(),
                'photo'      => $active->photo ? [
                    'id'         => $active->photo->id,
                    'thumb_url'  => $active->photo->thumb_url,
                    'medium_url' => $active->photo->medium_url,
                    'large_url'  => $active->photo->large_url,
                ] : null,
            ] : null,
            'last_decision' => $lastDecision ? [
                'status'        => $lastDecision->status,
                'reject_reason' => $lastDecision->reject_reason,
                'moderated_at'  => $lastDecision->moderated_at?->toIso8601String(),
            ] : null,
        ];
    }
}
