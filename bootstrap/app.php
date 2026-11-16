<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\V1\LoadUserRelations;
use App\Http\Middleware\V1\AdminRoleMiddleware;
use App\Http\Middleware\V1\TrackUserSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // Доверяем всем прокси (Cloudflare/Nginx) — Laravel будет
        // читать реальные IP юзеров из X-Forwarded-For.
        // ВАЖНО: если за Cloudflare, в проде лучше явно перечислить
        // IP Cloudflare вместо '*'. Но для старта '*' — нормально.
        $middleware->trustProxies(at: '*');
        // $middleware->trustProxies(at: [
        //     '127.0.0.1',           // локальный Nginx
        //     '10.0.0.5',            // внутренний IP Nginx-сервера
        //     // IP Cloudflare можно найти тут: https://www.cloudflare.com/ips/
        // ]);

        $middleware->web(append: [
            LoadUserRelations::class,

            // ПАКЕТ 6 (рекомендация №7): ведём Redis-реестр сессий
            // (user_id → id сессий + ip/ua/last_activity). Проводится
            // ПОСЛЕ контроллера: к этому моменту login уже сделал
            // session()->regenerate() → в реестр попадает финальный id.
            // Работает и для юзер-SPA, и для админки (обе через 'web').
            // Ошибка Redis не роняет запрос (best-effort внутри).
            TrackUserSession::class,
        ]);

        // Добавляем наш middleware в API группу (в начало)
        // FIX: раньше UpdateOnlineStatus добавлялся И в api-, И в web-группу.
        // Все API-роуты пользователя обёрнуты в ['web'] (routes/api.php) —
        // middleware выполнялся ДВАЖДЫ на каждый запрос. Web-группу убрали:
        // api-группы достаточно (admin-роуты тоже идут через неё).
        $middleware->api(append: [
            \App\Http\Middleware\V1\UpdateOnlineStatus::class,
        ]);

        // регистрируем алиас для middleware
        $middleware->alias([
            'admin.role' => AdminRoleMiddleware::class,
        ]);

        // "Route [login] not defined" (500 вместо 401 на /api/me).
        // Laravel 11-13 по умолчанию строит редирект гостей через
        // route('login') — такого роута в API-приложении нет, и исключение
        // падает прямо в middleware, ДО логики JSON-рендера. Наш логин
        // живёт на Next: для API/JSON отдаём null → 401 JSON (его подхватит
        // shouldRenderJsonWhen ниже), для гипотетических web-страниц —
        // страница логина фронтенда.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return rtrim(env('FRONTEND_URL', 'http://localhost:3001'), '/') . '/login';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // ============================================================
        // ПАКЕТ 5 (рекомендация №3 аудита Шага 1): единый конвейер 404.
        //
        // ПРОБЛЕМА: findOrFail() и route-binding при несущественном ID
        // выбрасывают ModelNotFoundException, Laravel конвертирует её в
        // NotFoundHttpException С ОРИГИНАЛЬНЫМ ТЕКСТОМ:
        //   {"message": "No query results for model [App\Models\Gift] 999"}
        // Т.е. ЛЮБОЙ 404-ответ раскрывал наружу внутренние имена моделей
        // (FQCN) — утечка внутренней информации для публичного API.
        //
        // РЕШЕНИЕ: для API-запросов — единый чистый JSON. Пользовательские
        // 404 (abort(404, '...') и notFound() из контроллеров) приходят
        // сюда уже с осмысленным текстом — их сохраняем как есть.
        //
        // ВАЖНО: контроллеры, которые возвращают свой 404-формат напрямую
        // (response()->json, а не исключение), НЕ затрагиваются — их
        // контракты с фронтендом не меняются.
        // ============================================================
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null; // не-API: дефолтная HTML-страница 404
            }

            // Автоматический 404 от Eloquent содержит имя модели — заменяем.
            // Пользовательские abort(404, 'текст') и route-404 — пропускаем
            // их сообщение (кроме случая, когда текст пуст).
            $message = str_starts_with($e->getMessage(), 'No query results for model')
                ? 'Запись не найдена.'
                : ($e->getMessage() !== '' ? $e->getMessage() : 'Запись не найдена.');

            return response()->json(['message' => $message], 404);
        });

        // Тот же случай, если колбэки получают исходное исключение ДО
        // конвертации в NotFoundHttpException (порядок зависит от версии
        // Laravel) — обрабатываем оба типа.
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json(['message' => 'Запись не найдена.'], 404);
        });
    })

    ->create();
