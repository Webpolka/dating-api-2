<?php

namespace App\Services\V1;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

/**
 * 
 * Сервис просмотров профилей.
 *
 * ВАЖНО: Все методы обёрнуты в try-catch.
 * При падении Redis — просмотры не учитываются, но просмотр профиля не роняется.
 * Buffer-записи не теряются: буфер чистится только ПОСЛЕ успешной записи в БД.
 * 
 * 1. Юзер A открывает анкету юзера B
 *  → ProfileController вызывает $service->recordViewIfNotRecent(A, B)
 *  → recordViewIfNotRecent проверяет в Redis (hasViewedRecently) — смотрел ли в течение 24ч
 *  → если НЕ смотрел → Redis::hset('profile_views:buffer', 'A:B', time())
 * 
 * 2. Redis-буфер содержит hash: { "A:B" => 1729884000, "C:D" => 1729884005, ... }
 *   Это быстро (Redis) — не нагружает БД при каждом просмотре.
 * 
 * 3. Каждую минуту cron запускает job ProcessProfileViewsBatch
 *  → job вызывает $service->processBatch()
 *  → processBatch:
 *     a) readBuffer() — Redis::hgetall (БУФЕР НЕ УДАЛЯЕТСЯ)
 *     b) парсит "A:B" → ['viewer_id'=>A, 'viewed_id'=>B, 'updated_at'=>...]
 *     c) чанками по 500 делает bulk upsert в profile_views
 *        INSERT ... ON CONFLICT (viewer_id, viewed_id) DO UPDATE
 *        SET updated_at = EXCLUDED.updated_at
 *     d) ТОЛЬКО после успешного upsert — hdel обработанных полей буфера
 *        (просмотры, пришедшие в буфер за время обработки, НЕ теряются)
 *     e) возвращает количество обработанных
 * 
 * 4. В БД profile_views теперь есть записи о просмотрах
 *   (схема: viewer_id, viewed_id, updated_at — см. миграцию 000032):
 *  - viewer_id | viewed_id | updated_at
 *  - 5         | 42        | 2025-10-26 18:25:00
 *  - 8         | 42        | 2025-10-26 18:26:00
 * 
 * 5. Запрос "кто смотрел анкету юзера 42":
 *  SELECT viewer_id FROM profile_views WHERE viewed_id = 42 ORDER BY updated_at DESC;
 */


class ProfileViewService
{
    private const BUFFER_KEY = 'profile_views:buffer';

    /**
     * Отдельный ключ дедупа "смотрел за 24ч" с собственным TTL.
     * FIX (А8): раньше дедуп проверял только буфер (hash), но буфер
     * чистится каждую минуту после flush в БД — «24 часа» фактически
     * не работали: повторный просмотр через 2 минуты снова попадал
     * в буфер и снова апдейтил updated_at. Теперь каждый уникальный
     * просмотр оставляет TTL-ключ на сутки — дедуп честный и дешёвый
     * (EXISTS по ключу), БД не дёргается.
     */
    private const RECENT_PREFIX = 'profile_views:recent:';
    private const RECENT_TTL = 86400; // 24 часа

    /**
     * Добавить просмотр в Redis-буфер.
     * При падении Redis — silent (лог warning).
     */
    public function recordView(int $viewerId, int $viewedId): void
    {
        try {
            $field = "{$viewerId}:{$viewedId}";
            $timestamp = time();
            Redis::hset(self::BUFFER_KEY, $field, $timestamp);

            // Дедуп-ключ на 24 часа (см. комментарий у RECENT_PREFIX)
            Redis::setex($this->recentKey($viewerId, $viewedId), self::RECENT_TTL, $timestamp);
        } catch (\Throwable $e) {
            Log::warning('ProfileViewService: recordView failed', [
                'viewer_id' => $viewerId,
                'viewed_id' => $viewedId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Прочитать буфер просмотров из Redis (НЕ удаляя его).
     * При падении Redis — возвращает пустой массив (нечего обрабатывать).
     *
     * FIX (А8, порядок операций): раньше здесь же делался Redis::del — буфер
     * удалялся ДО записи в БД, и любая ошибка upsert'а означала безвозвратную
     * потерю всех просмотров за минуту. Теперь буфер чистится точечно (hdel)
     * только ПОСЛЕ успешной записи в БД — данные дожидаются успешного flush.
     *
     * @return array Массив просмотров для записи в БД
     */
    public function readBuffer(): array
    {
        try {
            $buffer = Redis::hgetall(self::BUFFER_KEY);

            if (!is_array($buffer) || empty($buffer)) {
                return [];
            }

            return $buffer;
        } catch (\Throwable $e) {
            Log::warning('ProfileViewService: readBuffer failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Удалить из буфера только успешно обработанные поля.
     * Просмотры, пришедшие в буфер ЗА время обработки, остаются в Redis —
     * их заберёт следующий запуск job'а.
     */
    private function removeFromBuffer(array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        try {
            Redis::hdel(self::BUFFER_KEY, ...array_values($fields));
        } catch (\Throwable $e) {
            // Не страшно: на следующем запуске upsert просто перезапишет
            // те же записи (ON CONFLICT DO UPDATE — идемпотентен).
            Log::warning('ProfileViewService: removeFromBuffer failed', [
                'fields_count' => count($fields),
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Обработать весь буфер просмотров:
     *   1. Читаем буфер из Redis (readBuffer — буфер НЕ удаляется)
     *   2. Парсим ключи "viewerId:viewedId" → нормальный массив
     *   3. Bulk upsert в таблицу profile_views (чанками по 500)
     *   4. Только после успеха — чистим обработанные поля буфера (hdel)
     *   5. Возвращаем количество обработанных записей
     *
     * Вызывается из job ProcessProfileViewsBatch (по cron каждую минуту).
     *
     * FIX (А8, несуществующие колонки): таблица profile_views содержит
     * ТОЛЬКО viewer_id, viewed_id, updated_at (миграция 000032) — колонок
     * viewed_at/created_at в ней НЕТ. Раньше upsert писал во все три —
     * каждый запуск job'а падал с "column viewed_at does not exist",
     * а буфер к этому моменту уже был удалён из Redis → просмотры терялись
     * безвозвратно. Теперь пишем только в существующие колонки: момент
     * просмотра кладём в updated_at (сортировка "кто смотрел" идёт по ней —
     * индекс (viewed_id, updated_at)).
     *
     * @return int Количество обработанных просмотров
     */
    public function processBatch(): int
    {
        // 1. Читаем буфер, НЕ удаляя (данные живут в Redis до записи в БД)
        $buffer = $this->readBuffer();

        if (!is_array($buffer) || empty($buffer)) {
            return 0;
        }

        // 2. Парсим ключи "viewerId:viewedId" → массив строк для upsert.
        //    Ключи буфера запоминаем — по ним потом чистим Redis (hdel).
        $rows = [];
        $processedFields = [];
        foreach ($buffer as $field => $timestamp) {
            // $field = "viewerId:viewedId" — разделяем по первому ":"
            // (на случай если ID вдруг содержит ":" — хотя для bigint не должно)
            $parts = explode(':', $field, 2);
            if (count($parts) !== 2) {
                $processedFields[] = $field; // битый ключ — в мусор, но убираем из буфера
                continue;
            }

            [$viewerId, $viewedId] = $parts;
            $viewerId = (int) $viewerId;
            $viewedId = (int) $viewedId;

            if ($viewerId <= 0 || $viewedId <= 0) {
                $processedFields[] = $field; // мусор — убираем из буфера
                continue;
            }

            // Таблица profile_views: viewer_id, viewed_id, updated_at.
            // updated_at = момент последнего просмотра (идемпотентный upsert).
            $rows[$field] = [
                'viewer_id'  => $viewerId,
                'viewed_id'  => $viewedId,
                'updated_at' => date('Y-m-d H:i:s', (int) $timestamp),
            ];
        }

        // 3. Bulk upsert в БД — чанками по 500 (чтобы не упереться в лимит параметров)
        // upsert = INSERT ... ON CONFLICT (viewer_id, viewed_id) DO UPDATE
        // UNIQUE индекс (viewer_id, viewed_id) есть в миграции 000032.
        if (!empty($rows)) {
            try {
                $chunks = array_chunk(array_values($rows), 500);
                foreach ($chunks as $chunk) {
                    DB::table('profile_views')->upsert(
                        $chunk,
                        ['viewer_id', 'viewed_id'],  // unique columns
                        ['updated_at']               // что обновлять при конфликте
                    );
                }

                $processedFields = array_merge(
                    $processedFields,
                    array_keys($rows)
                );
            } catch (\Throwable $e) {
                Log::error('ProfileViewService: processBatch DB upsert failed', [
                    'rows_count' => count($rows),
                    'error'      => $e->getMessage(),
                ]);

                // Буфер НЕ трогаем — записанные в Redis поля ещё живы,
                // следующий запуск job'а повторит попытку. Просмотры
                // идемпотентны (ON CONFLICT DO UPDATE) — дублей не будет.
                return 0;
            }
        }

        // 4. Успех — чистим только обработанные поля буфера.
        $this->removeFromBuffer($processedFields);

        return count($rows);
    }

    /**
     * Проверить, смотрел ли viewer профиль viewed за последние N часов.
     * При падении Redis возвращает false (считаем что не смотрел).
     *
     * Два источника:
     *  1. TTL-ключ дедупа (живёт 24ч после просмотра) — основной;
     *  2. буфер (ещё не сброшенный в БД) — на случай, если TTL-ключ
     *     не успел записаться (partial failure).
     */
    public function hasViewedRecently(int $viewerId, int $viewedId, int $hours = 24): bool
    {
        try {
            // 1. TTL-ключ дедупа
            if (Redis::exists($this->recentKey($viewerId, $viewedId)) === 1) {
                return true;
            }

            // 2. Ещё лежит в буфере (flush раз в минуту)
            $field = "{$viewerId}:{$viewedId}";
            $timestamp = Redis::hget(self::BUFFER_KEY, $field);

            if (!$timestamp) {
                return false;
            }

            return (time() - (int) $timestamp) < ($hours * 3600);
        } catch (\Throwable $e) {
            Log::warning('ProfileViewService: hasViewedRecently failed, returning false', [
                'viewer_id' => $viewerId,
                'viewed_id' => $viewedId,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Ключ дедупа "viewer недавно смотрел viewed".
     */
    private function recentKey(int $viewerId, int $viewedId): string
    {
        return self::RECENT_PREFIX . $viewerId . ':' . $viewedId;
    }

    /**
     * Записать просмотр с проверкой дубликата.
     * При падении Redis — записываем в БД напрямую (без дедупликации).
     */
    public function recordViewIfNotRecent(int $viewerId, int $viewedId, int $hours = 24): void
    {
        try {
            if (!$this->hasViewedRecently($viewerId, $viewedId, $hours)) {
                $this->recordView($viewerId, $viewedId);
            }
        } catch (\Throwable $e) {
            Log::warning('ProfileViewService: recordViewIfNotRecent failed', [
                'viewer_id' => $viewerId,
                'viewed_id' => $viewedId,
                'error'     => $e->getMessage(),
            ]);
            // Fallback: записываем без проверки дубликата
            $this->recordView($viewerId, $viewedId);
        }
    }
}
