<?php

namespace App\Enums\V1;

/**
 * Причины отклонения заявки верификации пользователя.
 *
 * Все причины — пользовательские (модератор выбирает в UI).
 * Системных причин нет (верификация — single-shot заявка, обрабатывается
 * только модератором вручную, без job'ов).
 *
 * Симметрично с DiaryRejectReason по структуре.
 * Values sync с миграцией 0001_01_01_000040_create_verifications_table.php
 * (enum-колонка reject_reason в БД).
 */
enum VerificationRejectReason: string
{
    case Blurry = 'blurry';
    case Fake = 'fake';
    case NoCode = 'no_code';
    case Minor = 'minor';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Blurry  => 'Размытое / плохое качество',
            self::Fake    => 'Поддельное / не соответствует профилю',
            self::NoCode  => 'Не виден код верификации',
            self::Minor   => 'Несовершеннолетний на фото',
            self::Other   => 'Другое нарушение правил',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Blurry  => 'bg-yellow-500/10 text-yellow-500',
            self::Fake    => 'bg-red-500/10 text-red-500',
            self::NoCode  => 'bg-orange-500/10 text-orange-500',
            self::Minor   => 'bg-destructive/10 text-destructive',
            self::Other   => 'bg-secondary text-secondary-foreground',
        };
    }

    /**
     * Системная причина — генерируется автоматически, не модератором.
     * Для верификаций таких нет — все причины пользовательские.
     */
    public function isSystem(): bool
    {
        return false;
    }

    /**
     * Опции для Select модератора — value => label.
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn(self $case) => [
            $case->value => $case->label(),
        ])->toArray();
    }

    /**
     * Массив пользовательских значений (для валидации в FormRequest).
     * Использовать ТОЛЬКО этот метод в Rule::in() — он не пропустит
     * системные причины (хотя для верификаций их нет, метод оставлен
     * для симметрии с PhotoRejectReason / CommentRejectReason).
     */
    public static function userValues(): array
    {
        return collect(self::cases())
            ->filter(fn(self $case) => !$case->isSystem())
            ->map(fn(self $case) => $case->value)
            ->values()
            ->all();
    }

    /**
     * Label по строковому значению — для badge на уже отклонённых заявках.
     * Fallback на само значение если reason не найден в enum.
     */
    public static function labelFor(?string $reason): string
    {
        if (!$reason) return '—';
        try {
            return self::from($reason)->label();
        } catch (\ValueError $e) {
            return $reason;
        }
    }
} 