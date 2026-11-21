<?php

namespace App\Notifications\V1;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Уведомление юзеру об одобрении верификации.
 *
 * Каналы — по тем же правилам, что и PhotoModerated:
 *  - database: всегда (для бейджа в шапке).
 *  - mail: если email_enabled && email_settings['on_event'] !== false.
 *  - broadcast: если push_enabled (realtime через Reverb).
 *
 * Диспатчится из ModerateVerificationAction::approve.
 */
class VerificationApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected int $verificationId,
        protected ?int $userId,
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];

        $emailSettings = $notifiable->email_settings ?? [];

        if ($notifiable->email_enabled && ($emailSettings['on_event'] ?? true)) {
            $channels[] = 'mail';
        }

        if ($notifiable->push_enabled) {
            $channels[] = 'broadcast';
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Ваш аккаунт верифицирован')
            ->greeting("Здравствуйте, {$notifiable->name}!")
            ->line('Поздравляем! Ваша заявка на верификацию одобрена.')
            ->line('Теперь ваш профиль отмечен значком верификации — это повышает доверие других пользователей.')
            ->action('Перейти в профиль', url('/profile'));
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'verification_approved',
            'title' => '✅ Аккаунт верифицирован',
            'message' => 'Ваша заявка на верификацию одобрена. Профиль отмечен значком верификации.',
            'action_url' => url('/profile'),
            'data' => [
                'verification_id' => $this->verificationId,
                'user_id' => $this->userId,
                'status' => 'approved',
            ],
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'type' => 'verification_approved',
            'title' => '✅ Аккаунт верифицирован',
            'message' => 'Ваша заявка на верификацию одобрена.',
            'action_url' => url('/profile'),
            'timestamp' => now()->toDateTimeString(),
            'data' => [
                'verification_id' => $this->verificationId,
                'user_id' => $this->userId,
                'status' => 'approved',
            ],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Не удалось отправить VerificationApproved (User: {$this->userId}, Verification: {$this->verificationId}): " . $exception->getMessage());
    }
}
