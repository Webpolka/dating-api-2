<?php

namespace App\Services\V1;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Реестр пользовательских сессий (ПАКЕТ 6, рекомендация №7 аудита Шага 1).
 *
 * ЗАЧЕМ: подсистема принудительного logout (бан, удаление юзера, kill-сессий
 * в админке, logout-everywhere) раньше работала ПРЯМЫМ SQL по таблице
 * sessions в 6 местах. Это блокировало перевод сессий на Redis
 * (SESSION_DRIVER=redis): при redis-драйвере таблица пустеет, и забаненный
 * юзер ОСТАВАЛСЯ бы залогиненным (security-регрессия, зафиксировано в Пакете 5).
 *
 * Теперь все 6 мест ходят через этот сервис. Он driver-aware:
 *
 *   SESSION_DRIVER=database (сейчас) → таблица sessions остаётся источником
 *     истины: поведение побайтово совпадает со старым SQL (чтение/удаление
 *     строк таблицы) + параллельная чистка Redis-реестра на запись.
 *
 *   SESSION_DRIVER=redis (будущее) → источником истины становится Redis-реестр:
 *     SET sreg:user:{id} (id сессий) + HASH sreg:meta:{sid}
 *     (user_id, ip, user_agent, last_activity). Реестр ведётся middleware
 *     TrackUserSession (touch на каждый залогиненный запрос, троттлинг 60с)
 *     и листенером RemoveSessionFromRegistry (Logout).
 *
 * Механика «убить сессию» в redis-режиме — session()->getHandler()->destroy($id):
 * это контракт SessionHandlerInterface, работает для ЛЮБОГО драйвера
 * (DatabaseHandler удаляет строку, CacheHandler забывает ключ).
 *
 * ОТКАЗОУСТОЙЧИВОСТЬ: Redis-опции обёрнуты в try/catch — недоступность Redis
 * НИКОГДА не роняет запрос и не ломает бан/logout (в database-режиме реестр
 * вспомогательный, таблица самодостаточна). Первая ошибка логируется
 * (warning), дальше — тихо, чтобы не спамить лог на каждый запрос.
 *
 * Ключи живут на default-соединении (db 0), НЕ в cache-db: cache:clear
 * не должен вытирать реестр. Префикс sreg: + общий префикс приложения.
 *
 * Выключатель (тесты/авария): SESSION_REGISTRY_ENABLED=false — redis-часть
 * молча отключается; database-стратегия работает всегда.
 */
class SessionRegistry
{
    /** Префикс ключей реестра. */
    private const KEY_PREFIX = 'sreg:';

    /** Записали ли уже warning о недоступности Redis в этом процессе. */
    private static bool $redisFailureLogged = false;

    // ============================================
    // ЗАПИСЬ (middleware / Logout-листенер)
    // ============================================

    /**
     * Зарегистрировать/обновить сессию юзера в Redis-реестре.
     *
     * Вызывается TrackUserSession ПОСЛЕ контроллера: к этому моменту
     * login уже сделал session()->regenerate() → id финальный (старый
     * id, висящий в Login-событии ДО регенерации, сюда не попадает —
     * поэтому реестр ведёт middleware, а не Login-листенер).
     */
    public function touch(int $userId, string $sessionId, ?string $ip, ?string $userAgent): void
    {
        if ($userId <= 0 || $sessionId === '' || !$this->registryEnabled()) {
            return;
        }

        $this->redis(function ($redis) use ($userId, $sessionId, $ip, $userAgent): void {
            $ttl = $this->registryTtl();

            $redis->hmset(self::KEY_PREFIX . "meta:{$sessionId}", [
                'user_id'       => $userId,
                'ip'            => (string) ($ip ?? ''),
                'user_agent'    => (string) ($userAgent ?? ''),
                'last_activity' => time(),
            ]);
            $redis->expire(self::KEY_PREFIX . "meta:{$sessionId}", $ttl);

            $redis->sadd(self::KEY_PREFIX . "user:{$userId}", $sessionId);
            $redis->expire(self::KEY_PREFIX . "user:{$userId}", $ttl);
        });
    }

    /**
     * Убрать сессию из реестра (Logout-листенер; вызывается ДО
     * session()->invalidate(), поэтому id ещё валиден).
     */
    public function forget(int $userId, string $sessionId): void
    {
        if ($userId <= 0 || $sessionId === '' || !$this->registryEnabled()) {
            return;
        }

        $this->redis(function ($redis) use ($userId, $sessionId): void {
            $redis->srem(self::KEY_PREFIX . "user:{$userId}", $sessionId);
            $redis->del(self::KEY_PREFIX . "meta:{$sessionId}");
        });
    }

    // ============================================
    // ЧТЕНИЕ
    // ============================================

    /**
     * Все сессии юзера (для таба «Активные устройства» в админке).
     *
     * @return list<array{id: string, ip_address: string|null, user_agent: string|null, last_activity: int}>
     *         Отсортировано по last_activity DESC (как раньше SQL ORDER BY).
     */
    public function allFor(int $userId): array
    {
        if ($this->usesDatabaseTable()) {
            // Источник истины — таблица (BC: включая «зависшие» строки,
            // которые gc ещё не почистил — как в старом getUserSessions).
            return DB::table('sessions')
                ->where('user_id', $userId)
                ->orderByDesc('last_activity')
                ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
                ->map(fn($row) => [
                    'id'            => (string) $row->id,
                    'ip_address'    => $row->ip_address,
                    'user_agent'    => $row->user_agent,
                    'last_activity' => (int) $row->last_activity,
                ])
                ->all();
        }

        return $this->allForFromRegistry($userId);
    }

    /**
     * Найти сессию юзера (проверка владения!) без уничтожения.
     *
     * Возвращает метаданные для AdminLog (id + ip) или null, если сессии
     * нет ИЛИ она принадлежит другому юзеру (защита «не убить чужую» —
     * семантика прежнего WHERE id = ? AND user_id = ?).
     *
     * @return array{id: string, user_id: int, ip_address: string|null, user_agent: string|null, last_activity: int}|null
     */
    public function peekOne(int $userId, string $sessionId): ?array
    {
        if ($this->usesDatabaseTable()) {
            $row = DB::table('sessions')
                ->where('id', $sessionId)
                ->where('user_id', $userId)
                ->first(['id', 'user_id', 'ip_address', 'user_agent', 'last_activity']);

            return $row ? [
                'id'            => (string) $row->id,
                'user_id'       => (int) $row->user_id,
                'ip_address'    => $row->ip_address,
                'user_agent'    => $row->user_agent,
                'last_activity' => (int) $row->last_activity,
            ] : null;
        }

        $meta = $this->readMeta($sessionId);

        return ($meta !== null && (int) $meta['user_id'] === $userId) ? $meta : null;
    }

    // ============================================
    // УНИЧТОЖЕНИЕ
    // ============================================

    /**
     * Убить ОДНУ сессию юзера (проверив владение).
     *
     * @return array{id: string, user_id: int, ip_address: string|null, user_agent: string|null, last_activity: int}|null
     *         метаданные убитой сессии (для AdminLog) или null, если сессии нет / она чужая.
     */
    public function destroyOne(int $userId, string $sessionId): ?array
    {
        $meta = $this->peekOne($userId, $sessionId);

        if ($meta === null) {
            return null;
        }

        if ($this->usesDatabaseTable()) {
            // BC: удаление строки таблицы (участвует в транзакции вызывающего,
            // если тот вызвал внутри DB::transaction — как раньше).
            DB::table('sessions')
                ->where('id', $sessionId)
                ->where('user_id', $userId)
                ->delete();

            // Чистим копию в Redis-реестре (для будущего переезда на redis
            // — чтобы реестр не копил призраков). Отложенно, если мы внутри
            // DB-транзакции: Redis-задержки не должны её растягивать.
            $this->cleanupAfterCommit(fn () => $this->forget($userId, $sessionId));
        } else {
            // Driver-агностик: DatabaseHandler удалил бы строку,
            // CacheHandler (redis-сессии) забывает ключ.
            try {
                session()->getHandler()->destroy($sessionId);
            } catch (Throwable) {
                // сессия могла умереть между peek и destroy — не критично
            }
            $this->forget($userId, $sessionId);
        }

        return $meta;
    }

    /**
     * Убить ВСЕ сессии юзера (бан, удаление аккаунта, kill-all,
     * logout-everywhere). $exceptSessionId — сохранить текущую сессию
     * (logout-everywhere не выкидывает админа из текущего браузера).
     *
     * @return int количество завершённых сессий (для сообщений и AdminLog).
     */
    public function destroyAllFor(int $userId, ?string $exceptSessionId = null): int
    {
        if ($this->usesDatabaseTable()) {
            // BC: один DELETE с count'ом — семантика прежнего кода.
            $count = DB::table('sessions')
                ->where('user_id', $userId)
                ->when($exceptSessionId !== null, fn($q) => $q->where('id', '!=', $exceptSessionId))
                ->delete();

            // Параллельная чистка Redis-реестра (для будущего переезда на
            // redis-драйвер реестр не должен копить призраков). Best-effort,
            // отложенно до коммита, если вызов внутри транзакции.
            $this->cleanupAfterCommit(fn () => $this->cleanupRegistryFor($userId));

            return $count;
        }

        return $this->destroyAllFromRegistry($userId, $exceptSessionId);
    }

    // ============================================
    // СТРАТЕГИИ
    // ============================================

    /** Таблица sessions — источник истины только при database-драйвере. */
    private function usesDatabaseTable(): bool
    {
        return config('session.driver') === 'database';
    }

    /** Redis-реестр включён (выключатель для тестов/аварий). */
    private function registryEnabled(): bool
    {
        return (bool) config('session.registry_enabled', true);
    }

    /** TTL ключей реестра: время жизни сессии + 10 минут запаса. */
    private function registryTtl(): int
    {
        return ((int) config('session.lifetime', 120)) * 60 + 600;
    }

    /**
     * Сессия считается живой, пока её last_activity свежее lifetime.
     * (Явные kill/logout убирают запись из реестра сразу; этот фильтр —
     * страховка от регенерации id при логине и «заброшенных» записей.)
     */
    private function isFresh(int $lastActivity): bool
    {
        return $lastActivity >= time() - ((int) config('session.lifetime', 120)) * 60;
    }

    /** Прочитать метаданные сессии из реестра (null — записи нет).
     *  user_id обязателен — peekOne по нему проверяет владение. */
    private function readMeta(string $sessionId): ?array
    {
        $meta = null;

        $this->redis(function ($redis) use ($sessionId, &$meta): void {
            $raw = $redis->hgetall(self::KEY_PREFIX . "meta:{$sessionId}");
            if (!empty($raw['user_id'])) {
                $meta = [
                    'id'            => $sessionId,
                    'user_id'       => (int) $raw['user_id'],
                    'ip_address'    => ($raw['ip'] ?? '') !== '' ? $raw['ip'] : null,
                    'user_agent'    => ($raw['user_agent'] ?? '') !== '' ? $raw['user_agent'] : null,
                    'last_activity' => (int) ($raw['last_activity'] ?? 0),
                ];
            }
        });

        return $meta;
    }

    /**
     * Список сессий из Redis-реестра с ленивой чисткой призраков:
     * протухшие по времени ИЛИ уже несуществующие (handler->read() пуст)
     * записи удаляются из SET'а при первом же чтении.
     *
     * @return list<array{id: string, ip_address: string|null, user_agent: string|null, last_activity: int}>
     */
    private function allForFromRegistry(int $userId): array
    {
        $rows = [];

        $this->redis(function ($redis) use ($userId, &$rows): void {
            $setKey = self::KEY_PREFIX . "user:{$userId}";
            $sessionIds = $redis->smembers($setKey);

            foreach ($sessionIds as $sessionId) {
                $raw = $redis->hgetall(self::KEY_PREFIX . "meta:{$sessionId}");

                // Призрак: меты нет (истёк TTL) или запись протухла по времени.
                if (empty($raw['user_id']) || !$this->isFresh((int) ($raw['last_activity'] ?? 0))) {
                    $redis->srem($setKey, $sessionId);
                    $redis->del(self::KEY_PREFIX . "meta:{$sessionId}");
                    continue;
                }

                // Призрак: сессии физически нет (регенерация id при логине,
                // gc, ручной logout на другом инстансе) — чистим и пропускаем.
                $alive = true;
                try {
                    $payload = session()->getHandler()->read($sessionId);
                    $alive = $payload !== '' && $payload !== false;
                } catch (Throwable) {
                    $alive = true; // не смогли проверить — доверяем времени
                }
                if (!$alive) {
                    $redis->srem($setKey, $sessionId);
                    $redis->del(self::KEY_PREFIX . "meta:{$sessionId}");
                    continue;
                }

                $rows[] = [
                    'id'            => (string) $sessionId,
                    'ip_address'    => ($raw['ip'] ?? '') !== '' ? $raw['ip'] : null,
                    'user_agent'    => ($raw['user_agent'] ?? '') !== '' ? $raw['user_agent'] : null,
                    'last_activity' => (int) ($raw['last_activity'] ?? 0),
                ];
            }
        });

        usort($rows, fn($a, $b) => $b['last_activity'] <=> $a['last_activity']);

        return $rows;
    }

    /**
     * Убить все сессии юзера по реестру (redis-драйвер сессий).
     * Считаем только свежие записи — протухшие чистим без счёта
     * (destroy мёртвого id — no-op, а счёт нужен для честного «N шт.»).
     */
    private function destroyAllFromRegistry(int $userId, ?string $exceptSessionId): int
    {
        $destroyed = 0;

        $this->redis(function ($redis) use ($userId, $exceptSessionId, &$destroyed): void {
            $setKey = self::KEY_PREFIX . "user:{$userId}";
            $sessionIds = $redis->smembers($setKey);

            foreach ($sessionIds as $sessionId) {
                if ($exceptSessionId !== null && $sessionId === $exceptSessionId) {
                    continue;
                }

                $raw = $redis->hgetall(self::KEY_PREFIX . "meta:{$sessionId}");
                if (empty($raw['user_id']) || !$this->isFresh((int) ($raw['last_activity'] ?? 0))) {
                    // Призраки — чистим, не считаем.
                    $redis->srem($setKey, $sessionId);
                    $redis->del(self::KEY_PREFIX . "meta:{$sessionId}");
                    continue;
                }

                try {
                    session()->getHandler()->destroy($sessionId);
                } catch (Throwable) {
                    // продолжаем: остальные сессии обязаны умереть
                }

                $redis->srem($setKey, $sessionId);
                $redis->del(self::KEY_PREFIX . "meta:{$sessionId}");
                $destroyed++;
            }
        });

        return $destroyed;
    }

    /** Best-effort чистка реестра при табличном destroyAllFor. */
    private function cleanupRegistryFor(int $userId): void
    {
        $this->redis(function ($redis) use ($userId): void {
            $setKey = self::KEY_PREFIX . "user:{$userId}";
            $sessionIds = $redis->smembers($setKey);

            foreach ($sessionIds as $sessionId) {
                $redis->srem($setKey, $sessionId);
                $redis->del(self::KEY_PREFIX . "meta:{$sessionId}");
            }
        });
    }

    /**
     * Выполнить чистку реестра сейчас, а внутри DB-транзакции — после
     * коммита (при откате чистка не нужна: сессии остались жить).
     * Изоляция: Redis-задержки (например, при лежащем Redis) не должны
     * растягивать транзакции бана/удаления юзера.
     */
    private function cleanupAfterCommit(callable $fn): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($fn);
            return;
        }

        $fn();
    }

    // ============================================
    // REDIS-ДОСТУП (отказоустойчивая обёртка)
    // ============================================

    /**
     * Выполнить блок с Redis-соединением; при недоступности Redis —
     * тихо пропустить (первая ошибка за процесс — warning в лог).
     *
     * @param callable $fn fn($redis): void
     */
    private function redis(callable $fn): void
    {
        if (!$this->registryEnabled()) {
            return;
        }

        try {
            $fn(Redis::connection());
        } catch (Throwable $e) {
            if (!self::$redisFailureLogged) {
                self::$redisFailureLogged = true;
                Log::warning('SessionRegistry: Redis недоступен, реестр сессий работает в деградации: ' . $e->getMessage());
            }
        }
    }
}
