<?php

namespace App\Services\V1;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Сервис онлайн-статусов.
 *
 * ВАЖНО: Все методы обёрнуты в try-catch.
 * При падении Redis — методы возвращают безопасные дефолты:
 *  - markOnline/markOffline → silent (лог warning)
 *  - isOnline → false (юзер считается офлайн)
 *  - getOnlineStatuses → пустой массив
 *  - getOnlineCount → 0
 *
 * Это гарантирует, что падение Redis не роняет приложение.
 * Онлайн-статы будут неточными, но сайт продолжит работать.
 */
class UserOnlineService
{
    private const ONLINE_PREFIX = 'user:online:';
    private const ONLINE_TTL = 300;
    private const UPDATE_THROTTLE = 60;

    /**
     * Отметить юзера как онлайн.
     */
    public function markOnline(int $userId, bool $force = false): void
    {
        try {
            $key = $this->onlineKey($userId);

            // Throttling: проверяем когда последний раз обновляли
            if (!$force && $this->isRecentlyUpdated($key)) {
                return;
            }

            Redis::setex($key, self::ONLINE_TTL, time());

            Log::debug('🟢 [Online] User marked online', [
                'user_id' => $userId,
                'ttl' => self::ONLINE_TTL,
            ]);
        } catch (\Throwable $e) {
            Log::warning('UserOnlineService: markOnline failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Отметить юзера как оффлайн.
     */
    public function markOffline(int $userId): void
    {
        try {
            Redis::del($this->onlineKey($userId));

            Log::debug('🔴 [Online] User marked offline', [
                'user_id' => $userId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('UserOnlineService: markOffline failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Проверить онлайн ли юзер.
     * При падении Redis возвращает false (считаем офлайн).
     */
    public function isOnline(int $userId): bool
    {
        try {
            return Redis::exists($this->onlineKey($userId)) === 1;
        } catch (\Throwable $e) {
            Log::warning('UserOnlineService: isOnline failed, returning false', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Массовая проверка онлайн-статусов.
     * При падении Redis возвращает пустой массив (все считаются офлайн).
     *
     * @param array $userIds
     * @return array<int, bool>
     */
    public function getOnlineStatuses(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        try {
            $userIds = array_values(array_unique($userIds));

            $responses = Redis::pipeline(function ($pipe) use ($userIds) {
                foreach ($userIds as $userId) {
                    $pipe->exists($this->onlineKey($userId));
                }
            });

            if (!is_array($responses)) {
                $responses = [];
            }

            $result = [];
            foreach ($userIds as $index => $userId) {
                $value = $responses[$index] ?? 0;
                $result[$userId] = $value === 1;
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('UserOnlineService: getOnlineStatuses failed, returning empty', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Получить список ID онлайн юзеров из переданного списка.
     */
    public function filterOnlineUsers(array $userIds): array
    {
        $statuses = $this->getOnlineStatuses($userIds);
        return array_keys(array_filter($statuses));
    }

    /**
     * Получить timestamp последнего онлайна.
     * При падении Redis возвращает null.
     */
    public function getLastSeenTimestamp(int $userId): ?int
    {
        try {
            $value = Redis::get($this->onlineKey($userId));
            return $value ? (int) $value : null;
        } catch (\Throwable $e) {
            Log::warning('UserOnlineService: getLastSeenTimestamp failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Количество онлайн юзеров.
     * При падении Redis возвращает 0.
     *
     * FIX: раньше был блокирующий Redis::keys() — KEYS сканирует весь
     * keyspace и вешает однопоточный Redis (всё приложение ждёт). Заменено
     * на SCAN (инкрементальный, неблокирующий).
     *
     * FIX 2: вызов scan был с неверной сигнатурой — паттерн передавался
     * строкой, а Laravel-обёртка PhpRedisConnection::scan($cursor, $options)
     * ждёт МАССИВ опций: ['match' => ..., 'count' => ...]. Из-за этого
     * match не применялся и SCAN перебирал ВСЕ ключи keyspace, а в счётчик
     * попадали посторонние ключи. Теперь опции передаются правильно, ответ [cursor, keys] разбирается явно.
     */
    public function getOnlineCount(): int
    {
        try {
            $count = 0;

            /** @var \Illuminate\Contracts\Redis\Connection $connection */
            $connection = Redis::connection();

            $cursor = 0;

            do {
                // SCAN не блокирует Redis между итерациями.
                // Формат ответа: [новый курсор, пачка ключей] | false (скан завершён).
                $result = $connection->scan($cursor, [
                    'match' => self::ONLINE_PREFIX . '*',
                    'count' => 500,
                ]);

                if ($result === false) {
                    break;
                }

                [$cursor, $keys] = $result;

                $count += count((array) $keys);
            } while ((int) $cursor !== 0);

            return $count;
        } catch (\Throwable $e) {
            Log::warning('UserOnlineService: getOnlineCount failed', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    // ============================================
    // ВНУТРЕННИЕ МЕТОДЫ
    // ============================================

    private function onlineKey(int $userId): string
    {
        return self::ONLINE_PREFIX . $userId;
    }

    /**
     * Проверяем обновлялся ли ключ за последние UPDATE_THROTTLE секунд.
     * При падении Redis возвращает false (можно обновлять).
     */
    private function isRecentlyUpdated(string $key): bool
    {
        try {
            $ttl = Redis::ttl($key);

            if ($ttl <= 0) {
                return false;
            }

            $keyAge = self::ONLINE_TTL - $ttl;
            return $keyAge < self::UPDATE_THROTTLE;
        } catch (\Throwable $e) {
            return false;
        }
    }
}