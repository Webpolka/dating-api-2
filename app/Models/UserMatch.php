<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class UserMatch extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_UNMATCHED = 'unmatched';

    protected $table = 'user_matches';

    protected $fillable = [
        'user1_id',
        'user2_id',
        'status',
        'unmatched_by',
        'unmatched_at',
    ];

    protected $casts = [
        'unmatched_at' => 'datetime',
    ];

    // ============================================
    // СТАТИЧЕСКИЕ ХЕЛПЕРЫ (Бизнес-логика)
    // ============================================
    public static function createMatch(int $userA, int $userB): self
    {
        $user1Id = min($userA, $userB);
        $user2Id = max($userA, $userB);

        // FIX (найдено тестом SwipeMatchTest::test_rematch_reactivates_unmatched_match):
        // старый catch-23505 внутри внешней DB::transaction контроллера НЕ РАБОТАЛ —
        // упавший INSERT в Postgres АБОРТИРУЕТ транзакцию, и все SELECT в catch
        // падали «current transaction is aborted» → 500 на каждом re-match.
        // Правильно: сначала ищем пару (в т.ч. unmatched → реактивация),
        // INSERT — только если пары нет, под SAVEPOINT (вложенная транзакция).
        $existing = self::query()
            ->where('user1_id', $user1Id)
            ->where('user2_id', $user2Id)
            ->first();

        if ($existing !== null) {
            if ($existing->status === self::STATUS_UNMATCHED) {
                $existing->update([
                    'status' => self::STATUS_ACTIVE,
                    'unmatched_by' => null,
                    'unmatched_at' => null,
                ]);
            }

            return $existing;
        }

        try {
            // Гонка параллельных взаимных лайков: вложенная транзакция =
            // SAVEPOINT — упавшая вставка откатывается к сейвпоинту,
            // внешняя транзакция контроллера остаётся рабочей.
            return DB::transaction(fn (): self => self::create([
                'user1_id' => $user1Id,
                'user2_id' => $user2Id,
                'status' => self::STATUS_ACTIVE,
            ]));
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 23505) {
                // Параллельный winner закоммитил строку (INSERT в Postgres
                // ждёт конфликтующую транзакцию: 23505 = она закоммичена).
                return self::query()
                    ->where('user1_id', $user1Id)
                    ->where('user2_id', $user2Id)
                    ->firstOrFail();
            }

            throw $e;
        }
    }

    //  чтобы быстро подгружать мэтчи конкретного пользователя
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('user1_id', $userId)->orWhere('user2_id', $userId);
        });
    }

    // ============================================
    // СВЯЗИ
    // ============================================
    public function user1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user1_id');
    }

    public function user2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user2_id');
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================
    public function getPartner(int $userId): ?User
    {
        $relationName = $this->user1_id === $userId ? 'user2' : 'user1';

        return $this->getRelationValue($relationName);
    }

    public function unmatch(int $userId): bool
    {
        if ($this->status === self::STATUS_UNMATCHED) {
            return true;
        }

        return $this->update([
            'status' => self::STATUS_UNMATCHED,
            'unmatched_by' => $userId,
            'unmatched_at' => now(),
        ]);
    }
}
