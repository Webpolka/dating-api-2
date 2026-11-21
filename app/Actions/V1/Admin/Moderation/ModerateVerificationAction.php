<?php

namespace App\Actions\V1\Admin\Moderation;

use App\Models\AdminLog;
use App\Models\User;
use App\Models\Verification;
use App\Notifications\V1\VerificationApproved;
use App\Notifications\V1\VerificationRejected;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Бизнес-логика модерации верификаций пользователей.
 *
 * Архитектура 1:1 как ModeratePhotoAction:
 *  - Идемпотентность: approve/reject возвращают без действия, если заявка уже в целевом статусе.
 *  - lockForUpdate внутри транзакции: два модератора одновременно — второй видит уже изменённый статус и выходит.
 *  - AdminLog::record: единый аудит (action, before/after, admin, participants).
 *  - Cache::forget('admin_sidebar_stats'): сброс счётчика бейджа в сайдбаре админки.
 *  - Notification с дедупом через Cache (5 мин) — не спамим при повторных кликах.
 *
 * Связь с is_verified:
 *  - Verification::markAsApproved — ЕДИНСТВЕННОЕ место в системе, где
 *    проставляется user.is_verified. Хардкод из ModeratePhotoAction убран.
 *  - При reject — is_verified НЕ снимается (если юзер уже был верифицирован
 *    ранее, статус сохраняется; отклонение новой заявки не лишает значка).
 */
class ModerateVerificationAction
{
    // ============================================
    // ОДИНОЧНЫЕ ДЕЙСТВИЯ
    // ============================================

    public function approve(Verification $verification, User $admin, bool $notify = true): Verification
    {
        // Идемпотентность: уже approved — ничего не делаем.
        if ($verification->status === Verification::STATUS_APPROVED) {
            return $verification;
        }

        $before = [
            'status'        => $verification->getOriginal('status'),
            'reject_reason' => $verification->getOriginal('reject_reason'),
        ];

        // lockForUpdate — два модератора одновременно одобряют одну заявку.
        $alreadyApproved = false;
        DB::transaction(function () use ($verification, $admin, &$alreadyApproved) {
            $locked = Verification::lockForUpdate()->find($verification->id);
            if ($locked && $locked->status === Verification::STATUS_APPROVED) {
                $alreadyApproved = true;
                return;
            }
            // markAsApproved идемпотентен внутри (двойная защита),
            // плюс проставляет user.is_verified = true.
            $verification->markAsApproved($admin->id);
        });

        if ($alreadyApproved) {
            return $verification;
        }

        $after = [
            'status'        => Verification::STATUS_APPROVED,
            'moderated_by'  => $admin->id,
            'moderated_at'  => now()->toDateTimeString(),
            'context'       => [
                'verification_id' => $verification->id,
                'user_id'         => $verification->user_id,
                'photo_id'        => $verification->photo_id,
                'code'            => $verification->code,
            ],
        ];

        // Уведомляем юзера (с дедупом 5 мин, как в PhotoModerated).
        if ($notify && $verification->user) {
            $cacheKey = "verification_approved_notif_{$verification->user_id}";
            if (!Cache::has($cacheKey)) {
                $verification->user->notify(new VerificationApproved($verification->id, $verification->user_id));
                Cache::put($cacheKey, true, now()->addMinutes(5));
            }
        }

        AdminLog::record(
            'verification.approve',
            $verification,
            $admin,
            $before,
            $after,
            participants: [$verification->user_id],
        );

        $this->clearCaches();

        return $verification;
    }

    public function reject(Verification $verification, User $admin, string $reason, bool $notify = true): void
    {
        // Идемпотентность: уже rejected с той же reason — ничего не делаем.
        if ($verification->status === Verification::STATUS_REJECTED && $verification->reject_reason === $reason) {
            return;
        }

        $before = [
            'status'        => $verification->getOriginal('status'),
            'reject_reason' => $verification->getOriginal('reject_reason'),
        ];

        $alreadyRejected = false;
        DB::transaction(function () use ($verification, $admin, $reason, &$alreadyRejected) {
            $locked = Verification::lockForUpdate()->find($verification->id);
            if ($locked && $locked->status === Verification::STATUS_REJECTED && $locked->reject_reason === $reason) {
                $alreadyRejected = true;
                return;
            }
            $verification->markAsRejected($admin->id, $reason);
        });

        if ($alreadyRejected) {
            return;
        }

        $after = [
            'status'        => Verification::STATUS_REJECTED,
            'reject_reason' => $reason,
            'moderated_by'  => $admin->id,
            'moderated_at'  => now()->toDateTimeString(),
            'context'       => [
                'verification_id' => $verification->id,
                'user_id'         => $verification->user_id,
                'photo_id'        => $verification->photo_id,
                'code'            => $verification->code,
            ],
        ];

        if ($notify && $verification->user) {
            try {
                $verification->user->notify(new VerificationRejected($verification->id, $verification->user_id, $reason));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('VerificationRejected notification failed', [
                    'verification_id' => $verification->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        AdminLog::record(
            'verification.reject',
            $verification,
            $admin,
            $before,
            $after,
            participants: [$verification->user_id],
        );

        $this->clearCaches();
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function clearCaches(): void
    {
        // Сброс бейджа pending-верификаций в сайдбаре.
        Cache::forget('admin_sidebar_stats');
        Cache::forget('admin_dashboard_live_v2');
    }
} 