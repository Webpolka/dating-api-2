<?php

namespace App\Http\Middleware\V1;

use App\Services\V1\SessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ведение Redis-реестра сессий (ПАКЕТ 6, рекомендация №7 аудита Шага 1).
 *
 * На каждый ЗАЛОГИНЕННЫЙ stateful-запрос (SPA-кука юзера или админа —
 * обе группы роутов ходят через 'web') регистрируем/обновляем текущую
 * сессию в SessionRegistry: {user_id, ip, user_agent, last_activity}.
 *
 * Зачем middleware, а не Login-листенер: контроллеры логина делают
 * Auth::attempt() → session()->regenerate() — СТАРЫЙ id сессии умирает,
 * финальный появляется только ПОСЛЕ контроллера. Мы трекаем после
 * $next($request) → в реестр попадает живой id.
 *
 * Троттлинг: не чаще раза в 60 сек на сессию (флаг в самой сессии —
 * платим 0 доп. запросов: сессия и так пишется на каждый запрос).
 * Точность last_activity в реестре ±60 сек — для «был(а) N минут назад»
 * достаточно.
 *
 * Отказ Redis (и любая ошибка) НИКОГДА не роняет запрос: реестр —
 * вспомогательный механизм (см. SessionRegistry).
 */
class TrackUserSession
{
    /** Флаг последнего touch в данных сессии (троттлинг записи в реестр). */
    private const TOUCHED_AT_KEY = '_session_registry_touched_at';

    /** Интервал обновления реестра, секунды. */
    private const TOUCH_INTERVAL = 60;

    public function __construct(private readonly SessionRegistry $registry)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // После контроллера: логин уже отработал (regenerate сделан),
        // юзер уже в сессии — регистрируем ИТОГОВОЕ состояние.
        try {
            if (!$request->hasSession()) {
                return $response;
            }

            $session = $request->session();

            // Только stateful-аутентификация (web guard по куке).
            // Bearer-токены (мобильные клиенты) сессий не создают — мимо.
            $userId = auth()->check() ? (int) auth()->id() : 0;
            $sessionId = (string) $session->getId();

            if ($userId <= 0 || $sessionId === '') {
                return $response;
            }

            $touchedAt = $session->get(self::TOUCHED_AT_KEY);
            if (is_int($touchedAt) && $touchedAt > time() - self::TOUCH_INTERVAL) {
                return $response;
            }

            $this->registry->touch(
                $userId,
                $sessionId,
                $request->ip(),
                $request->userAgent(),
            );

            $session->put(self::TOUCHED_AT_KEY, time());
        } catch (Throwable) {
            // Реестр не должен ломать запросы ни при каких условиях.
        }

        return $response;
    }
}
