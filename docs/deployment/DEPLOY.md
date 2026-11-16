# Развёртывание (deploy)

Соглашения: staging — проверка релизов, prod — живой трафик.
Окружение 1:1 с `compose.yaml` (Postgres 16 + PostGIS 3.4, Redis, Reverb,
Horizon; Mailpit — только dev).

---

## 1. Требования

| Компонент | Версия | Зачем |
| --- | --- | --- |
| PHP | ^8.3, ext: pdo_pgsql, pgsql, intl, mbstring, gd, zip | платформа |
| PostgreSQL + PostGIS | 16 / 3.4 (`postgis/postgis:16-3.4-alpine`) | БД + гео (миграция `0001` создаёт расширение) |
| Redis | любой актуальный | кэш, очереди, broadcast, реестр сессий |
| Node | для фронта (Next.js) | юзер-SPA и админка |
| Composer | 2.x | зависимости |

## 2. Переменные окружения (ключевые)

Полный разбор — `docs/environment/PART-1.env и окружение.md`.
Минимум для запуска (`cp .env.example .env` и проверить):

| Переменная | Прод-значение | Комментарий |
| --- | --- | --- |
| `APP_ENV` | `production` | |
| `APP_KEY` | сгенерировать | `php artisan key:generate` |
| `APP_URL` | `https://api.example.ru` | |
| `FRONTEND_URL` | `https://app.example.ru` | для редиректов 401/верификации |
| `APP_DEBUG` | `false` | ОБЯЗАТЕЛЬНО на проде |
| `DB_*` | Postgres (SSL по необходимости) | `DB_CONNECTION=pgsql` |
| `SESSION_DRIVER` | `database` | реестр сессий driver-aware (см. ниже) |
| `SESSION_REGISTRY_ENABLED` | `true` | реестр сессий (kill/бан → logout) |
| `CACHE_STORE` / `QUEUE_CONNECTION` | `redis` | |
| `BROADCAST_CONNECTION` | `reverb` | WebSocket-события |
| `APP_FOUNDERS` | id founder-аккаунтов | защита от понижения/бана через админку |

## 3. Установка / обновление релиза

```bash
# первый раз
git clone <repo> && cd dating-api
composer install --no-dev --optimize-autoloader
cp .env.example .env && nano .env          # таблица выше
php artisan key:generate
mkdir -p storage/framework/{cache,sessions,views} storage/logs
chmod -R ug+rwX storage bootstrap/cache

# каждый релиз
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force                # новые миграции (вкл. индексы Пакета 4)
php artisan config:cache && php artisan route:cache
php artisan queue:restart                  # воркеры подхватят новый код
```

Откат миграций на проде не используется — только вперёд
(миграции Пакетов 2/4/6 пишутся с учётом повторного прогона).

## 4. Фоновые процессы

| Процесс | Команда | Что делает |
| --- | --- | --- |
| Воркеры очередей | `php artisan horizon` | очереди: realtime-события (П5), рефанды, медиа, рассылки |
| WebSocket | `php artisan reverb:start` | чат/realtime (Reverb) |
| Шедулер (cron) | `* * * * * php artisan schedule:run` | задачи ниже |

Шедулер (`routes/console.php`):

| Задача | Период | Комментарий |
| --- | --- | --- |
| `ProcessProfileViewsBatch` | каждую минуту | батчинг просмотров анкет из Redis |
| `subscriptions:process` | 5 мин | продление/истечение подписок (FIX В6: `onOneServer`) |
| `broadcasts:send-scheduled` | каждую минуту | отложенные рассылки |
| `broadcasts:check-stuck` | 5 мин | зависшие рассылки |

Все задачи — `withoutOverlapping()` + `onOneServer()` (мультисерверный прод
без дублей).

## 5. Сессии и принудительный logout

`SessionRegistry` (Пакет 6) — единая точка управления сессиями:

- `SESSION_DRIVER=database` (текущий прод): источником истины остаётся
  таблица `sessions`; kill/бан/удаление юзера работают как раньше,
  параллельно ведётся Redis-реестр для будущего переезда;
- `SESSION_DRIVER=redis` (будущее): реестр становится источником истины —
  прямой SQL по `sessions` уже не используется нигде.

Проверка после деплоя: админка → юзер → «Активные устройства» → kill —
устройство logout'ается.

## 6. CI (GitHub Actions)

`.github/workflows/ci.yml` на каждый push/PR:

1. линт всех `*.php` (`php -l`);
2. Pint — код-стайл (`vendor/bin/pint --test`);
3. PHPUnit — полный прогон на `postgis/postgis:16-3.4-alpine`
   (БД `testing`, Redis в тестах не нужен: кэш/сессии/очереди — array,
   `SESSION_REGISTRY_ENABLED=false`).

Красный CI = релиз не мержится. Локально то же самое: `php artisan test`.

## 7. Приёмка релиза на staging

1. Развернуть ветку релиза (раздел 3).
2. `php artisan test` — все 13 файлов зелёные (окружение staging 1:1 CI).
3. Golden-контракт листингов (после изменений в юзерских табах админки):
   `php artisan test --filter=UserListingsContractTest` — зелёный =
   форматы ответов не уехали, фронтенд не требует доработок.
4. Smoke по админке вручную: список юзеров → карточка → пара табов →
   kill сессии → бан тестового аккаунта.
5. Проверить фоновые: `horizon` без failed jobs, `reverb` отдаёт события
   (открыть чат в двух окнах).

## 8. Мониторинг и эксплуатация

| Инструмент | Дашборд | Зачем |
| --- | --- | --- |
| Horizon | `/horizon` | очереди, failed jobs, повторы |
| Pulse | `/pulse` | метрики приложения (если включён) |
| Mailpit (dev) | `:8025` | ловля писем |

Логи: `storage/logs/laravel.log`; аудит действий админов — раздел
«Аудит» админки (`admin_logs`, пишется `AdminLog::record` из Actions).

## 9. Известные ограничения (честный список на передачу)

- Юзерские API-роуты не имеют отдельного banned-check middleware — защита
  реализована kill'ом сессий при бане (SessionRegistry) и проверкой на
  логине. Sanctum-токены, выданные до бана, не отзываются автоматически
  (SPA-cookie авторизация основная; при переходе на token-based добавить
  отзыв токенов в `ToggleUserBanAction`).
- `MockAcquiringService` — заглушка эквайринга; реальный платёжный шлюз
  подключается заменой сервиса (контракт `pay/status/sync/refund`).
- Мобильных пушей нет — только database/mail/broadcast каналы
  уведомлений.
