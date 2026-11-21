<?php

namespace App\Notifications\V1;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Уведомление юзеру об отклонении верификации.
 *
 * $reason — значение из Verification::REASON_* (blurry/fake/no_code/minor/other).
 * В уведомлении переводим в человекочитаемое описание для юзера.
 *
 * Диспатчится из ModerateVerificationAction::reject.
 */
class VerificationRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected int $verificationId,
        protected ?int $userId,
        protected string $reason,
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
            ->subject('Заявка на верификацию отклонена')
            ->greeting("Здравствуйте, {$notifiable->name}!")
            ->line('К сожалению, ваша заявка на верификацию отклонена.')
            ->line('Причина: ' . $this->reasonDescription())
            ->line('Вы можете подать новую заявку, устранив замечания.')
            ->action('Перейти в профиль', url('/profile'));
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'verification_rejected',
            'title' => '❌ Верификация отклонена',
            'message' => 'Заявка на верификацию отклонена. Причина: ' . $this->reasonDescription(),
            'action_url' => url('/profile'),
            'data' => [
                'verification_id' => $this->verificationId,
                'user_id' => $this->userId,
                'status' => 'rejected',
                'reason' => $this->reason,
            ],
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'type' => 'verification_rejected',
            'title' => '❌ Верификация отклонена',
            'message' => 'Заявка на верификацию отклонена. Причина: ' . $this->reasonDescription(),
            'action_url' => url('/profile'),
            'timestamp' => now()->toDateTimeString(),
            'data' => [
                'verification_id' => $this->verificationId,
                'user_id' => $this->userId,
                'status' => 'rejected',
                'reason' => $this->reason,
            ],
        ]);
    }

    /**
     * Человекочитаемое описание причины отклонения.
     * Используется в mail и database/broadcast payload.
     */
    private function reasonDescription(): string
    {
        return match ($this->reason) {
            'blurry'   => 'фото размытое или плохого качества',
            'fake'     => 'фото выглядит поддельным или не соответствует профилю',
            'no_code'  => 'на фото отсутствует или не виден код верификации',
            'minor'    => 'на фото виден несовершеннолетний',
            'other'    => 'нарушение правил верификации',
            default    => 'нарушение правил верификации',
        };
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Не удалось отправить VerificationRejected (User: {$this->userId}, Verification: {$this->verificationId}): " . $exception->getMessage());
    }
} 