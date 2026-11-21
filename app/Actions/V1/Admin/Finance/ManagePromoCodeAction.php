<?php

namespace App\Actions\V1\Admin\Finance;

use App\Models\AdminLog;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Управление промокодами (thin-controller pattern).
 *
 * По образцу ManageGiftAction:
 *  - store / update / toggleStatus возвращают модель.
 *  - destroy(int $id): ?array — сам резолвит код ВНУТРИ транзакции
 *    (lockForUpdate, защита от TOCTOU), возвращает null (не найден → 404)
 *    либо ['deleted' => bool, 'used_count' => int].
 *  - bulkGenerate возвращает количество созданных кодов.
 *
 * Особенность удаления: если код уже использовали (есть usages) — нельзя
 * удалять физически (нарушится финансовая история), деактивируем.
 *
 * Логирование: AdminLog с action'ами promo_code.create / .update / .deactivate
 * / .delete / .toggle_status / .bulk_generate — форматы before/after как
 * в ManageGiftAction.
 *
 * Код генерации: если в store передан пустой 'code' — генерируем через
 * PromoCode::generateCode(). В bulkGenerate всегда генерируем с опц. prefix.
 */
class ManagePromoCodeAction
{
    // ============================================
    // CRUD
    // ============================================

    public function store(User $admin, array $validated): PromoCode
    {
        // Если code не передан — генерируем случайный.
        $code = isset($validated['code']) && $validated['code'] !== ''
            ? Str::upper(trim($validated['code']))
            : PromoCode::generateCode();

        $promo = PromoCode::create([
            'code'           => $code,
            'discount_type'  => $validated['discount_type'],
            'discount_value' => $validated['discount_value'] ?? 0,
            'duration_days'  => $validated['duration_days'] ?? null,
            'max_uses'       => $validated['max_uses'] ?? null,
            'used_count'     => 0,
            'expires_at'     => $validated['expires_at'] ?? null,
            'user_id'        => $validated['user_id'] ?? null,
            'is_active'      => $validated['is_active'] ?? true,
        ]);

        AdminLog::record('promo_code.create', $promo, $admin, null, [
            'code'           => $promo->code,
            'discount_type'  => $promo->discount_type,
            'discount_value' => (float) $promo->discount_value,
            'duration_days'  => $promo->duration_days,
            'max_uses'       => $promo->max_uses,
            'expires_at'     => $promo->expires_at?->toIso8601String(),
            'user_id'        => $promo->user_id,
            'is_active'      => $promo->is_active,
            'context'        => ['promo_code_id' => $promo->id],
        ]);

        $this->clearCache();

        return $promo;
    }

    public function update(PromoCode $promo, User $admin, array $validated): PromoCode
    {
    
        // ПОЛИТИКА: промокод полностью immutable по semantic-полям.
        // Разрешены ТОЛЬКО 3 поля: max_uses, expires_at, is_active.
        //   - is_active — всегда toggle.
        //   - max_uses — если used_count > 0, только увеличивать (≥ used_count).
        //   - expires_at — если used_count > 0, только продлевать (≥ текущего).
        // FormRequest уже это валидирует; здесь дублируем на уровне action
        // (defence in depth — даже если FormRequest обойдён через curl/Postman,
        // action не применит запрещённые поля).
        //
      
        // discount_value / duration_days / user_id — НЕ принимаются FormRequest'ом
        // и НЕ применяются action'ом. Если админ ошибся при создании — пусть
        // создаст новый промокод и деактивирует старый.
        $before = $promo->only(['max_uses', 'expires_at', 'is_active']);

        $updateData = [];

    
        if (array_key_exists('is_active', $validated)) {
            $updateData['is_active'] = $validated['is_active'];
        }

      
        if (array_key_exists('max_uses', $validated) && $validated['max_uses'] !== null) {
            $newMax = (int) $validated['max_uses'];
            // Если код уже использовали — только увеличивать.
            if ($promo->used_count === 0 || $newMax >= $promo->used_count) {
                $updateData['max_uses'] = $newMax;
            }
         
        }

        if (array_key_exists('expires_at', $validated) && $validated['expires_at'] !== null) {
            $newExpiry = \Carbon\Carbon::parse($validated['expires_at']);
            // Если код уже использовали — только продлевать.
            if ($promo->used_count === 0 || !$promo->expires_at || $newExpiry->gte($promo->expires_at)) {
                $updateData['expires_at'] = $newExpiry;
            }
            
        }

        if (empty($updateData)) {           
            return $promo;
        }

        $promo->update($updateData);

        AdminLog::record('promo_code.update', $promo, $admin, $before, [           
            'max_uses'   => $promo->max_uses,
            'expires_at' => $promo->expires_at?->toIso8601String(),
            'is_active'  => $promo->is_active,
            'used_count_at_update' => $promo->used_count,         
            'context'    => ['promo_code_id' => $promo->id, 'code' => $promo->code],
        ]);

        $this->clearCache();

        return $promo;
    }

    /**
     * Удалить промокод (или деактивировать, если уже использовали).
     *
     * @return array|null null — не найден; иначе ['deleted' => bool, 'used_count' => int].
     */
    public function destroy(int $id, User $admin): ?array
    {
        return DB::transaction(function () use ($id, $admin) {
            $promo = PromoCode::lockForUpdate()->find($id);
            if (!$promo) {
                return null;
            }

            $usedCount = PromoCodeUsage::where('promo_code_id', $promo->id)->count();

            // Уже использовали — деактивируем, не удаляем (история финансов).
            if ($usedCount > 0) {
                $before = ['is_active' => $promo->getOriginal('is_active')];
                $promo->update(['is_active' => false]);

                AdminLog::record('promo_code.deactivate', $promo, $admin, $before, [
                    'is_active'    => false,
                    'deactivated_by' => $admin->id,
                    'context'      => [
                        'promo_code_id' => $promo->id,
                        'code'          => $promo->code,
                        'used_count'   => $usedCount,
                    ],
                ]);
                $this->clearCache();

                return ['deleted' => false, 'used_count' => $usedCount];
            }

            // Никто не использовал — удаляем физически.
            AdminLog::record('promo_code.delete', $promo, $admin, null, [
                'status'     => 'destroyed',
                'deleted_by' => $admin->id,
                'context'    => ['promo_code_id' => $promo->id, 'code' => $promo->code],
            ]);
            $promo->delete();
            $this->clearCache();

            return ['deleted' => true, 'used_count' => 0];
        });
    }

    public function toggleStatus(PromoCode $promo, User $admin): PromoCode
    {
        $before = ['is_active' => $promo->getOriginal('is_active')];
        $promo->update(['is_active' => !$promo->is_active]);

        AdminLog::record('promo_code.toggle_status', $promo, $admin, $before, [
            'is_active'  => $promo->is_active,
            'toggled_by' => $admin->id,
            'context'    => ['promo_code_id' => $promo->id, 'code' => $promo->code],
        ]);

        $this->clearCache();

        return $promo;
    }

    // ============================================
    // BULK GENERATION
    // ============================================

    /**
     * Сгенерировать N промокодов с одинаковыми параметрами.
     *
     * @param  User   $admin
     * @param  array  $validated BulkGeneratePromoCodesRequest:
     *     - count (1-1000)
     *     - discount_type, discount_value, duration_days
     *     - max_uses, expires_at, user_id, is_active
     *     - code_prefix (опционально — например "BLACKFRIDAY")
     * @return int Количество созданных кодов.
     *
     * Защита от коллизий: для каждого кода пытаемся insert, при 23505
     * (unique violation) регенерируем code и пробуем снова (до 5 попыток).
     * Если за 5 попыток не удалось — пропускаем (маловероятно при 32^10).
     */
    public function bulkGenerate(User $admin, array $validated): int
    {
        $count = max(1, min(1000, (int) ($validated['count'] ?? 1)));
        $prefix = isset($validated['code_prefix']) && $validated['code_prefix'] !== ''
            ? Str::upper(trim($validated['code_prefix']))
            : null;

        $created = 0;
        $failedAttempts = 0;
        $maxAttemptsPerCode = 5;

        for ($i = 0; $i < $count; $i++) {
            for ($attempt = 0; $attempt < $maxAttemptsPerCode; $attempt++) {
                $code = PromoCode::generateCode(prefix: $prefix);

                try {
                    $promo = PromoCode::create([
                        'code'           => $code,
                        'discount_type'  => $validated['discount_type'],
                        'discount_value' => $validated['discount_value'] ?? 0,
                        'duration_days'  => $validated['duration_days'] ?? null,
                        'max_uses'       => $validated['max_uses'] ?? null,
                        'used_count'     => 0,
                        'expires_at'     => $validated['expires_at'] ?? null,
                        'user_id'        => $validated['user_id'] ?? null,
                        'is_active'      => $validated['is_active'] ?? true,
                    ]);
                    $created++;
                    break; // успех — выходим из retry-цикла
                } catch (QueryException $e) {
                    if ($e->getCode() === '23505') {
                        // unique violation — коллизия, регенерируем code
                        $failedAttempts++;
                        continue;
                    }
                    throw $e;
                }
            }
        }

        // ОДНА запись AdminLog на всю bulk-операцию (как в ModeratePhotoAction bulk).
        AdminLog::record('promo_code.bulk_generate', null, $admin, null, [
            'count'           => $created,
            'requested_count' => $count,
            'collisions'      => $failedAttempts,
            'discount_type'   => $validated['discount_type'],
            'discount_value'  => (float) ($validated['discount_value'] ?? 0),
            'duration_days'   => $validated['duration_days'] ?? null,
            'max_uses'        => $validated['max_uses'] ?? null,
            'expires_at'      => $validated['expires_at'] ?? null,
            'user_id'         => $validated['user_id'] ?? null,
            'code_prefix'     => $prefix,
            'context'         => ['admin_id' => $admin->id],
        ]);

        $this->clearCache();

        return $created;
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function clearCache(): void
    {
        Cache::forget('admin_promo_codes_counts');
    }
}
