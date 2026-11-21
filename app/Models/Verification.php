<?php

namespace App\Models;

use App\Enums\V1\VerificationRejectReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Verification extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    // NOTE: Причины отклонения вынесены в enum App\Enums\V1\VerificationRejectReason.
    // Констант REASON_* здесь больше нет — используй VerificationRejectReason::Blurry->value,
    // VerificationRejectReason::userValues() для валидации, VerificationRejectReason::labelFor()
    // для UI. Это симметрично с PhotoRejectReason / DiaryRejectReason.

    /**
     * Длина одноразового кода верификации.
     * 6 символов — достаточно для human-readable ввода и защиты от перебора
     * (32^6 = ~10^9 комбинаций, плюс TTL 10 мин в Redis).
     */
    public const CODE_LENGTH = 6;

    /**
     * Алфавит кода: без похожих символов (0/O, 1/I/L) — юзер не перепутает
     * при ручном вводе кода с бумажки в кадр.
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** TTL кода в Redis до submit'а заявки (минут). */
    public const CODE_TTL_MINUTES = 10;

    protected $fillable = [
        'user_id',
        'photo_id',
        'code',
        'status',
        'reject_reason',
        'moderated_by',
        'moderated_at',
    ];

    protected $casts = [
        'moderated_at' => 'datetime',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    // ============================================
    // ХЕЛПЕРЫ МОДЕРАЦИИ
    // ============================================

    /**
     * Идемпотентное одобрение: если уже approved — return false (ничего не делаем).
     * Возвращает true, если статус реально изменился.
     *
     * Ставит user.is_verified = true (это единственное место в системе,
     * где проставляется is_verified — хардкод из ModeratePhotoAction убран).
     */
    public function markAsApproved(int $adminId): bool
    {
        if ($this->status === self::STATUS_APPROVED) {
            return false;
        }

        $updated = $this->update([
            'status' => self::STATUS_APPROVED,
            'moderated_by' => $adminId,
            'moderated_at' => now(),
            'reject_reason' => null,
        ]);

        if ($updated && $this->user) {
            $this->user->update(['is_verified' => true]);
        }

        return $updated;
    }

    /**
     * Идемпотентное отклонение: если уже rejected с той же reason — return false.
     */
    public function markAsRejected(int $adminId, string $reason): bool
    {
        if ($this->status === self::STATUS_REJECTED && $this->reject_reason === $reason) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_REJECTED,
            'moderated_by' => $adminId,
            'moderated_at' => now(),
            'reject_reason' => $reason,
        ]);
    }

    // ============================================
    // СТАТИЧЕСКИЕ ХЕЛПЕРЫ
    // ============================================

    /**
     * Сгенерировать одноразовый код верификации (6 символов, без похожих).
     * Используется в Web VerificationController::requestCode.
     */
    public static function generateCode(): string
    {
        return Str::upper(
            collect(range(1, self::CODE_LENGTH))
                ->map(fn () => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)])
                ->implode('')
        );
    }

    /**
     * Найти активную (pending) заявку юзера.
     * Используется в Web API (status/submit) и в AdminAPI (queue карточки).
     */
    public static function findActiveForUser(int $userId): ?self
    {
        return self::where('user_id', $userId)
            ->where('status', self::STATUS_PENDING)
            ->latest('id')
            ->first();
    }
}
