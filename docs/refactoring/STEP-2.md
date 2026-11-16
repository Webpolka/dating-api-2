# REFACTORING — Часть 2: аудит-роадмап (Пакеты 1–7)

Дата: 2026-11-13 … 2026-11-16
Основа: аудит бэкенда по итогам Шага 1 — 52 находки (группы А/Б/В/…).
Формат сдачи: каждый пакет = **diff + текстовые пояснения + zip-архив**,
с поэтапным подтверждением владельцем проекта.

Все изменения проверены структурным `php -l`-эквивалентом (0 синтаксических
ошибок), URL эндпоинтов и форматы JSON-ответов сохранены — фронтенды
(юзерский SPA и админка) доработок не требуют. Поведенческие изменения
только там, где находка аудита была функциональным багом (см. Пакет 3).

---

## Состав роадмапа

| # | Пакет | Архив сдачи | Статус |
| --- | --- | --- | --- |
| 1 | Финансы | `dating-api-step2-package1-money.zip` | ✅ принят |
| 2 | Безопасность | — (сдавался diff'ом) | ✅ принят |
| 3 | Чаты и матчи | — (сдавался diff'ом) | ✅ принят |
| 4 | Производительность | `dating-api-step2-package4-perf.zip` | ✅ принят |
| 5 | Архитектура | `dating-api-step2-package5-arch.zip` | ✅ принят |
| 6 | Листинги + реестр сессий | `dating-api-step2-package6-listings-sessions.zip` (+ `.diff`) | ✅ принят |
| 7 | Тесты + документация + CI | `dating-api-step2-package7-tests-docs.zip` | ✅ принят |

Каждый пакет сдавался поверх предыдущего — финальное состояние репозитория
включает все семь.

---

## Пакет 1 «Финансы» (money)

**Цель:** деньги — самый критичный домен. Идемпотентность оплаты, честные
статусы транзакций, события платежей.

- `SubscriptionController::pay/renew` — общая логика идемпотентности
  (FIX В5):
  - заголовок `Idempotency-Key` — повторный запрос с тем же ключом
    возвращает существующую транзакцию, не создавая новую;
  - анти-даблклик без ключа — живая pending-транзакция того же плана
    моложе 60 сек возвращается вместо новой;
  - защита от покупки одного tier дважды.
- События домена: `TransactionRefunded`, `TransactionRefundFailed`,
  `TransactionSynced`.
- Джобы: `ProcessRefundJob`, `ProcessSyncJob` — тяжёлые операции
  эквайринга вынесены из request-цикла.
- Админ: ручной рефанд (`POST /api/admin/transactions/{id}/refund`,
  `RefundTransactionRequest`, enum `RefundReason`).
- Тесты: `tests/Feature/Api/SubscriptionPaymentTest.php` (идемпотентность,
  дубль tier, анти-даблклик, валидация).

## Пакет 2 «Безопасность» (security)

**Цель:** защита админки, анти-брутфорс, антифрод, границы ролей.

- `RateLimitServiceProvider` — лимитер `admin-login` (5 попыток/мин на
  email+IP) и `admin-login-by-email` (10/15 мин) на `POST /api/admin/login`.
- `AdminAuthService` — централизованная логика логина стаффа (учёт
  троттлеров, сессии).
- `AdminRoleMiddleware` — параметризованные роли:
  `admin.role` (все staff) / `admin.role:admin,moderator` / `admin.role:admin`;
  бан стаффа проверяется на каждом запросе (logout сразу, не по
  SESSION_LIFETIME).
- Founders (`APP_FOUNDERS`): защита от понижения/бана через админку,
  БЕЗ обхода middleware (role=user → доступ к админке закрыт и у founder'а —
  устранён скрытый бэкдор).
- Антифрод: `FraudAlertController` + `ResolveFraudAlertAction`
  (resolve-and-ban / resolve-with-warning / false-positive),
  `BlockSignalController` + `ModerateBlockSignalAction` (сигналы блокировок,
  бан по сигналу).
- Тесты: `tests/Feature/Admin/UserBanTest.php` (бан/unban, уведомления).

## Пакет 3 «Чаты и матчи» (chats & matches)

**Цель:** починить главный функциональный баг дейтинга и гонки.

- **FIX 1 (функциональный баг):** мэтч НИКОГДА не создавался — API
  возвращал `is_match=true`, но `UserMatch::create` не вызывался нигде,
  кроме сидера. Не работали: создание чата при мэтче, уведомления,
  исключение пары из ленты. Теперь `SwipeController::store` вызывает
  `UserMatch::createMatch` при взаимном положительном свайпе.
- **FIX 2 (гонка):** upsert свайпа + проверка взаимности + создание мэтча —
  в ОДНОЙ транзакции; коллизия двойного клика по unique решена через
  SAVEPOINT-повтор.
- `UserMatch::createMatch` — пара приводится к `user1_id < user2_id`,
  unmatched-мэтчи реактивируются (без дублей).
- `UserMatchObserver` — при мэтче: инвалидация кэшей лент обоих, чат
  (`Chat::getOrCreateBetween`), broadcast + уведомления; ремэтч
  (unmatched → active) обрабатывается отдельно.
- `UnreadCounterService` + `SyncUnreadCountersToDb` — счётчики непрочитанных
  в Redis с периодической синхронизацией в БД.

## Пакет 4 «Производительность» (perf)

**Цель:** чек-листы аудита А8 и Б7 + находки В1/В2 по индексам.

- Миграция `2026_11_16_000001_drop_dead_indexes_add_verifications_user_index`:
  - В1 — недостающий индекс `verifications(user_id)` (FK без индекса =
    Seq Scan на растущей таблице);
  - В2 — удаление мёртвых одиночных low-cardinality индексов, покрытых
    составными (`swipes_user_id_type_index`, `user_matches_status_index`
    и др.).
- Partial index `stop_words(category, action) WHERE is_active` — под
  точный запрос фильтрации в `index()`.
- Батч-обработка просмотров профилей: `ProcessProfileViewsBatch`
  (просмотры накапливаются и применяются очередью, не на каждый запрос).
- `InvalidateAllSearchCacheJob` — массовая инвалидация кэша поиска
  (ленты) при глобальных изменениях.

## Пакет 5 «Архитектура» (arch)

**Цель:** единообразие слоёв по рекомендациям №1, №3, №6, №8 аудита.

- №1 Realtime → очередь: ВСЕ broadcast-события реализуют `ShouldQueue`
  (`NewMessage`, `ChatMessageReceived`, `NewMatch`, support-события и др.)
  — WebSocket-отправка больше не живёт в request-цикле.
- №3 Единый конвейер ошибок:
  - `InsufficientCreditsException` — доменное исключение вместо голого
    `\Exception` (402 только для «мало кредитов», всё прочее — 500 без
    утечки внутренностей);
  - `bootstrap/app.php` — конвейер 404 для API: `No query results for
    model [FQCN]` заменён на чистый `{"message": "Запись не найдена."}`
    (устранена утечка имён моделей в публичном 404).
- №6 Валидация → FormRequests (хвосты): `SettingController`,
  `PageController` (Validator-фасад), `SubscriptionController::pay/renew`
  (ручной формат 422), инлайн-замыкание `POST /settings/theme` →
  `SettingsController::updateTheme` + `UpdateThemeRequest`.
- №8 Кэш справочников: каталог подарков (`Gift::CACHE_KEY`, 10 мин,
  инвалидация из `Gift::booted`) — модалка подарков больше не делает
  SELECT на каждое открытие.
- S5-7: `SendGiftAction` извлечён из `GiftController::store` (контроллер —
  только HTTP-обвязка).
- Тесты: `RealtimeEventsTest`, `AuthValidationTest`, `ThemeSettingsTest`.

## Пакет 6 «Листинги + реестр сессий» (listings + sessions)

**Цель:** рекомендации №4 и №7 аудита — оба «обязательных» пункта,
закрытых одним пакетом.

### Разбор монолита UserListingsController (рекомендация №4)

`UserListingsController` (1477 строк) разобран на доменные контроллеры
пользовательской страницы админки — 16 tab-методов разнесены по доменам:

| Контроллер | Табы |
| --- | --- |
| `UserTabListingController` (базовый) | общая пагинация/сериализация |
| `UserContentListingsController` | photos, photo-comments, diaries, diary-comments |
| `UserActivityListingsController` | dating (swipes/matches), chats, events |
| `UserFinanceListingsController` | finances, gifts, cards, promocodes |
| `UserAuditListingsController` | broadcasts, admin-logs, auth-logs |

Общие параметры фильтров — `UserListRequest`. Форматы ответов сохранены
ПОБАЙТОВО, что фиксирует golden-тест `UserListingsContractTest`
(см. процедуру приёмки ниже).

### Реестр сессий SessionRegistry (рекомендация №7)

Прямой SQL по таблице `sessions` в 6 местах (kill-сессий, бан → logout,
удаление юзера → logout, logout-everywhere) заменён на `SessionRegistry`:

- driver-aware: `database` — побайтово старое поведение; `redis` —
  реестр `sreg:user:{id}` + `sreg:meta:{sid}` (готовность к переезду
  сессий на Redis без security-регрессии);
- `TrackUserSession` (web-группа) — регистрирует живые сессии после
  `session()->regenerate()`, троттлинг 60 сек;
- `ManageUserSessionsAction` — kill/kill-all из админки;
- `RemoveSessionFromRegistry` — листенер события Logout;
- `SESSION_REGISTRY_ENABLED=false` в `phpunit.xml` (герметичность тестов).
- Тесты: `SessionRegistryTest`, `UserSessionsTest`, `UserListingsContractTest`.

## Пакет 7 «Тесты + документация + CI» (этот)

**Цель:** передаваемость проекта — «не стыдно показать другому программисту».

### Тесты (было 9 / ~1777 строк, стало 13 / ~2650)

| Файл | Что закрывает |
| --- | --- |
| `Feature/Api/SwipeMatchTest.php` | свайпы/мэтчи: FIX 1 (мэтч создаётся в БД), гонка двойного клика, rewind, ремэтч, self-swipe |
| `Feature/Api/GiftFlowTest.php` | подарки/балансы: атомарное списание, снапшоты, 402/403/400/422/404 |
| `Feature/Admin/DiaryModerationTest.php` | модерация дневников: approve/reject, идемпотентность, AdminLog, уведомления, role-matrix |
| `Feature/Admin/AdminCrudSmokeTest.php` | smoke всех list-эндпоинтов админки + role-matrix 3 групп + CRUD-цикл категорий блога |

### Документация

- этот журнал (`docs/refactoring/STEP-2.md`);
- спецификация админ-API (`docs/api/ADMIN-API.md`);
- гайд развёртывания (`docs/deployment/DEPLOY.md`);
- индекс `docs/README.md` дополнен.

### CI

- `.github/workflows/ci.yml` — на каждый push/PR: линт всех PHP-файлов,
  Pint (style), полный прогон PHPUnit на Postgres 16 + PostGIS 3.4
  (окружение 1:1 с прод-образом `postgis/postgis:16-3.4-alpine`).

### Эррата P7.1: хотфикс совместимости с Laravel 13 (v13.38.0)

Первый же `composer install` на чистой машине упал на `artisan package:discover`:
`Interface "Illuminate\Contracts\Queue\ShouldHandleAfterCommit" not found`.
Причина: в Laravel 13 из фреймворка УДАЛЕНЫ контракты `ShouldHandleAfterCommit`
(queue) и `ShouldBroadcastAfterCommit` (broadcasting) — они существовали только
в Laravel 11–12, а код Пакетов 1/5 на них ссылался. Исправлено, семантика
after-commit сохранена:

| Файл | Было | Стало |
| --- | --- | --- |
| `app/Listeners/V1/RevokePurchasedItems.php` | `implements ShouldQueue, ShouldHandleAfterCommit` | `implements ShouldQueueAfterCommit` (наследует `ShouldQueue`; Dispatcher ставит job-у `afterCommit = true`) |
| `app/Events/V1/` — 7 чат-эвентов | `implements ShouldBroadcastAfterCommit` | `implements ShouldBroadcast` + `public bool $afterCommit = true` (в L13 after-commit включается свойством: job `BroadcastEvent` читает `$event->afterCommit`) |
| `database/migrations/2026_11_16_000001_drop_dead_indexes...php` | `DROP INDEX IF EXISTS x CONCURRENTLY` | `DROP INDEX CONCURRENTLY IF EXISTS x` — неверный порядок ключевых слов PG, миграция падала на ЛЮБОМ прогоне |
| `app/Actions/V1/Web/SendGiftAction.php` | `'message' => $validated['message']` | `?? null` — подарок БЕЗ сообщения падал 500 («Undefined array key "message"») |
| `app/Models/UserMatch.php::createMatch()` | catch-23505 внутри внешней транзакции (упавший INSERT в Postgres АБОРТИРУЕТ транзакцию — recover-SELECT в catch падал «current transaction is aborted») | проверка пары ДО insert + SAVEPOINT вокруг insert — ре-мэтч (лайк после unmatch) падал 500 |

Тесты приведены к реальному окружению: PHPUnit 12 (атрибут `#[DataProvider]`
вместо docblock-аннотации — аннотации в 12-й не работают), enum-сравнения в
`DiaryModerationTest` (`DiaryStatus`-кейс вместо строковой константы —
`status` кастуется в enum), helper `loginAs()` с `app('auth')->forgetGuards()`
(sanctum-гард кэширует юзера между запросами одного теста — смена актёра
без сброса = чужой 403), URL юзера в user-API — по **slug**
(`User::getRouteKeyName() === 'slug'`), а не по id.

**Верификация:** `composer install` → `artisan package:discover` — зелёные;
смоук-скан всех 132 use-импортов из `app/` + `tests/` и require всех 482
PHP-файлов против vendor v13.38.0 — битых символов нет; полный прогон PHPUnit
на Postgres 17 + PostGIS 3.6: **152 теста / 488 ассертов — OK**; Pint по всем
изменённым файлам — чисто; `composer update --lock` синхронизировал
content-hash (варнинг «lock file is not up to date» при install убран).

---

## Процедура приёмки разбора UserListingsController (golden-контракт)

`UserListingsContractTest` — «золотой файл» форматов 16 tab-эндпоинтов,
зафиксированный по СТАРОМУ коду. Порядок на staging:

1. Ветка ДО разбора: `php artisan test --filter=UserListingsContractTest` — зелёный.
2. Ветка ПОСЛЕ разбора: тот же прогон — зелёный.
3. Совпадение = форматы не уехали, админ-фронтенд не требует доработок.

Тест структурный (наборы ключей, а не данные) — работает на любом
датасете staging.

## Как прогнать тесты локально

```bash
composer install
# тестовая БД (Postgres + PostGIS):
#   createdb testing && psql testing -c "CREATE EXTENSION IF NOT EXISTS postgis;"
APP_KEY=base64:... php artisan test
```

Требования: Postgres с PostGIS (миграция `0001` создаёт расширение),
`DB_DATABASE=testing` — тесты гоняются только с RefreshDatabase,
Redis в тестах не используется (`SESSION_REGISTRY_ENABLED=false`,
кэш/очереди/сессии — array).
