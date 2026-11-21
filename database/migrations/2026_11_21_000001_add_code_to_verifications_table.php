<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verification flow: добавляем колонку code + UNIQUE partial на один
 * активный pending-запрос на юзера.
 *
 * `code` — одноразовый 6-символьный код (без похожих символов 0/O/1/I/L),
 * который бек выдаёт юзеру на странице верификации (см. Web VerificationController::requestCode).
 * Юзер делает селфи с этим кодом на бумажке/жестом и грузит фото (Photo type=verification),
 * после чего POST /api/verification/submit создаёт Verification с этим code.
 *
 * Колонка хранит код истории (для аудита): какой именно код был использован
 * в заявке. Живой TTL кода (10 мин до submit) — в Redis (verify:code:{user_id}).
 *
 * UNIQUE partial index гарантирует: у юзера НЕ может быть двух pending
 * верификаций одновременно. При попытке submit второй раз — UniqueConstraint
 * → 422 "У вас уже есть заявка на рассмотрении".
 *
 * Индекс (user_id) уже добавлен миграцией 2026_11_16_000001.
 * Составной (status, created_at) — в исходной миграции 0001_01_01_000040.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verifications', function (Blueprint $table) {
            // 6 символов из алфавита без похожих (см. Verification::generateCode).
            // Nullable — для будущих заявок без кода (если появится другой метод верификации).
            $table->string('code', 8)->nullable()->after('photo_id');
        });

        // Один активный pending на юзера.
        // Partial UNIQUE — НЕ блокирует approved/rejected записи (историю),
        // но гарантирует уникальность pending на момент submit.
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS verifications_user_pending_unique ' .
            'ON verifications (user_id) WHERE status = \'pending\''
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS verifications_user_pending_unique');

        Schema::table('verifications', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
