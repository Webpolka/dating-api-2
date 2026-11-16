<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GeoController extends Controller
{
    /**
     * GET /api/geo/search?q=...
     *
     * Поиск городов и стран (автокомплит, дёргается на каждый ввод символа
     * в форме регистрации/анкеты). Роут публичный.
     *
     * FIX (Seq Scan на каждый keystroke): раньше — LOWER(col) LIKE '%q%'
     * по cities и geo_translations. Ведущий % + LOWER() по обеим сторонам =
     * гарантированный Seq Scan на таблицах GeoNames (сотни тысяч городов,
     * миллионы переводов) — при наборе текста десятками пользователей
     * PostgreSQL упирается в CPU.
     *
     * Теперь:
     *  - ILIKE + GIN-индексы pg_trgm (миграция add_geo_and_search_indexes):
     *    поиск по подстрокам без учёта регистра идёт по индексу;
     *  - результаты кэшируются на 10 минут — популярные префиксы
     *    (Мос, Каз, Мин...) больше не дёргают БД вообще.
     */
    public function search(Request $request): JsonResponse
    {
        $searchTerm = trim((string) $request->get('q', ''));

        if (mb_strlen($searchTerm) < 2) {
            return response()->json([]);
        }

        // Ключ — нормализованный запрос (регистр не влияет на результат).
        $results = Cache::remember(
            'geo_search:' . md5(mb_strtolower($searchTerm)),
            600,
            fn () => $this->doSearch($searchTerm)
        );

        return response()->json($results);
    }

    private function doSearch(string $searchTerm): array
    {
        $results = [];
        $pattern = "%{$searchTerm}%";

        // Ищем города, джоиня таблицу переводов (locale = ru)
        $cities = City::select('cities.*', 'gt.name as translated_name')
            ->leftJoin('geo_translations as gt', function ($join) {
                $join->on('cities.geoname_id', '=', 'gt.geoname_id')
                     ->where('gt.locale', 'ru');
            })
            ->where(function ($query) use ($pattern) {
                // ILIKE — регистронезависимый поиск (PostgreSQL);
                // поддерживается trgm-индексами городов и переводов.
                $query->where('cities.name', 'ilike', $pattern)
                      ->orWhere('gt.name', 'ilike', $pattern);
            })
            ->with('state:id,name')
            ->limit(10)
            ->get();

        foreach ($cities as $city) {
            // Если есть русский перевод, берем его, иначе базовое имя
            $displayName = $city->translated_name ?: $city->name;

            $results[] = [
                'id' => $city->id,
                'type' => 'city',
                'name' => $displayName,
                'region' => $city->state?->name,
                // Добавляем "г. " перед названием города
                'label' => 'г. ' . $displayName . ($city->state ? ' (' . $city->state->name . ')' : ''),
            ];
        }

        // Ищем страны так же
        $countries = Country::select('countries.*', 'gt.name as translated_name')
            ->leftJoin('geo_translations as gt', function ($join) {
                $join->on('countries.geoname_id', '=', 'gt.geoname_id')
                     ->where('gt.locale', 'ru');
            })
            ->where(function ($query) use ($pattern) {
                $query->where('countries.name', 'ilike', $pattern)
                      ->orWhere('gt.name', 'ilike', $pattern);
            })
            ->limit(5)
            ->get();

        foreach ($countries as $country) {
            $displayName = $country->translated_name ?: $country->name;

            $results[] = [
                'id' => $country->id,
                'type' => 'country',
                'name' => $displayName,
                'region' => null,
                'label' => $displayName . ' (Вся страна)',
            ];
        }

        return $results;
    }
}
