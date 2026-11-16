<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FIX: индексы под тяжёлые запросы поиска.
 *
 * 1) pg_trgm + GIN на cities.name и geo_translations.name:
 *    GeoController::search ищет по подстроке (ILIKE '%q%') на каждый keystroke
 *    автокомплита гео-формы. Без trgm-индекса — Seq Scan по таблицам GeoNames
 *    (сотни тысяч городов / миллионы переводов). С GIN gin_trgm_ops — поиск
 *    по подстроке идёт по индексу.
 *
 * 2) users.created_at (partial): фильтр «новые анкеты» (is_new) и сортировка
 *    sort=new в UserSearchService работают по users.created_at — индекса
 *    не было, на больших данных это filter/sort по несмежному столбцу.
 *
 * Условия partial-индекса users совпадают с базовым фильтром ленты
 * (см. UserSearchService::search) — чтобы индекс покрывывал ровно те
 * строки, которые ищем.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Миграция только для PostgreSQL (в тестах может быть sqlite).
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Расширение для trigram-индексов (есть в стандартном образе postgres/postgis).
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX IF NOT EXISTS cities_name_trgm_idx ON cities USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS geo_translations_name_trgm_idx ON geo_translations USING gin (name gin_trgm_ops)');

        DB::statement(
            "CREATE INDEX IF NOT EXISTS users_created_at_idx ON users (created_at DESC) " .
            "WHERE deleted_at IS NULL AND status = 'active' AND role = 'user'"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS cities_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS geo_translations_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS users_created_at_idx');
        // Расширение pg_trgm не дропаем — его могут использовать другие индексы.
    }
};
