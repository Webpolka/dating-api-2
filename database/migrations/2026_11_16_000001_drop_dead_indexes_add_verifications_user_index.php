<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FIX (В1, недостающий индекс) + FIX (В2, мёртвые индексы).
 *
 * === В1: verifications(user_id) ===
 * FK user_id был БЕЗ индекса. Верификация юзера читается по user_id
 * (eager load, проверка статуса) — на растущей таблице это Seq Scan.
 * PostgreSQL НЕ создаёт индексы на FK автоматически.
 *
 * === В2: мёртвые индексы (пишутся при каждом INSERT/UPDATE, никогда не читаются) ===
 * Все они — одиночные low-cardinality индексы, полностью покрытые
 * составными индексами тех же таблиц (планировщик всегда выбирает составной):
 *
 *  1. swipes_user_id_type_index          — покрыт unique(user_id, target_user_id)
 *                                          (лента: whereNotExists по user_id) и
 *                                          partial swipes_match_check_index
 *                                          (проверка взаимности: target_user_id,
 *                                          user_id, type WHERE rewinded_at IS NULL).
 *  2. user_matches_status_index          — покрыт [user1_id, status, created_at]
 *                                          и [user2_id, status, created_at].
 *  3. transactions_status_index          — покрыт [status, type, created_at]
 *                                          (фин-дашборд) и [user_id, status, created_at].
 *  4. photos_status_index                — покрыт [status, type, created_at]
 *                                          (очередь модерации) и [user_id, status, type]
 *                                          (eager load фото ленты).
 *  5. chat_participants_is_hidden_index  — boolean (почти все false — селективность
 *                                          ~0), покрыт [user_id, is_hidden, last_message_at].
 *  6. verifications_status_index         — покрыт [status, created_at].
 *  7. reports_reason_index               — WHERE reason без остальных условий
 *                                          нигде не выполняется.
 *  8. reports_status_index               — покрыт [status, created_at].
 *
 * Каждый мёртвый индекс — это лишняя запись в B-tree на каждый INSERT/UPDATE
 * (swipes и photos — самые write-нагруженные таблицы дейтинга) + место на диске
 * + время на восстановление бэкапа. Планировщик их не использует.
 *
 * DROP INDEX CONCURRENTLY не берёт блокировку записи таблицы, поэтому дропы
 * безопасны на живом проде. CONCURRENTLY нельзя выполнять в транзакции —
 * миграция запускается вне транзакции ($withinTransaction = false).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        // === В1: недостающий индекс ===
        if (! Schema::hasIndex('verifications', 'verifications_user_id_index')) {
            Schema::table('verifications', function (Blueprint $table) {
                $table->index('user_id', 'verifications_user_id_index');
            });
        }

        // === В2: мёртвые индексы (только PostgreSQL — в sqlite-тестах их имён нет) ===
        if ($isPgsql) {
            $deadIndexes = [
                'swipes_user_id_type_index',
                'user_matches_status_index',
                'transactions_status_index',
                'photos_status_index',
                'chat_participants_is_hidden_index',
                'verifications_status_index',
                'reports_reason_index',
                'reports_status_index',
            ];

            foreach ($deadIndexes as $index) {
                // CONCURRENTLY: без ACCESS EXCLUSIVE-блокировки таблицы.
                // Синтаксис Postgres: DROP INDEX CONCURRENTLY IF EXISTS <name>
                // (CONCURRENTLY стоит ДО IF EXISTS — иначе syntax error).
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index);
            }
        }
    }

    public function down(): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        // Возвращаем всё как было (индексы бесполезные, но обратимость — закон).
        if ($isPgsql) {
            $restore = [
                'CREATE INDEX IF NOT EXISTS swipes_user_id_type_index ON swipes (user_id, type)',
                'CREATE INDEX IF NOT EXISTS user_matches_status_index ON user_matches (status)',
                'CREATE INDEX IF NOT EXISTS transactions_status_index ON transactions (status)',
                'CREATE INDEX IF NOT EXISTS photos_status_index ON photos (status)',
                'CREATE INDEX IF NOT EXISTS chat_participants_is_hidden_index ON chat_participants (is_hidden)',
                'CREATE INDEX IF NOT EXISTS verifications_status_index ON verifications (status)',
                'CREATE INDEX IF NOT EXISTS reports_reason_index ON reports (reason)',
                'CREATE INDEX IF NOT EXISTS reports_status_index ON reports (status)',
            ];

            foreach ($restore as $sql) {
                DB::statement($sql);
            }
        }

        if (Schema::hasIndex('verifications', 'verifications_user_id_index')) {
            Schema::table('verifications', function (Blueprint $table) {
                $table->dropIndex('verifications_user_id_index');
            });
        }
    }
};
