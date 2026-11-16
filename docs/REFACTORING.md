# REFACTORING — Часть 1: структура бэкенда (API)

Дата: 2026-11-13
Объём: 320 → 393 PHP-файла; все изменения проверены `php -l` (0 синтаксических ошибок)
и PSR-4-аудитом (namespace = путь файла). URL всех эндпоинтов и форматы JSON-ответов
сохранены без изменений — админ-фронтенд не требует доработок.

---

## Итоговая структура `app/`

```
app/
├── Actions/
│   ├── Admin/
│   │   ├── Content/      ManageGiftAction, ManageBlogPostAction
│   │   ├── Moderation/   ModeratePhoto/PhotoComment/Diary/DiaryComment/Report/
│   │   │                 Dating/StopWord/BlockSignal, ManageDiaryRubricAction,
│   │   │                 ResolveFraudAlertAction
│   │   ├── Support/      SendSupportChatMessageAction, ToggleChatLockAction
│   │   ├── System/       ManageStaffAction, ManageSettingsAction
│   │   └── Users/        DeleteUser, ToggleUserBan, ManageUserProfile/Sessions/Blocks
│   └── Api/              SendGiftAction (пользовательское API)
├── Console/Commands/     (без изменений)
├── DTO/                  ContentFilterResult (бывш. Services/)
├── Enums/                (без изменений)
├── Events/               (без изменений)
├── Http/
│   ├── Controllers/
│   │   ├── AdminController.php   ← НОВЫЙ базовый класс админ-контроллеров
│   │   ├── Admin/V1/
│   │   │   ├── Auth/             AuthController
│   │   │   ├── Users/            UserController
│   │   │   ├── Moderation/       Photo, PhotoComment, Diary, DiaryComment, Report,
│   │   │   │                     Dating, BlockSignal, FraudAlert, StopWord
│   │   │   ├── Content/          Blog*, Page, Gift, Broadcast
│   │   │   ├── Finance/          Transaction, SubscriptionPlan
│   │   │   ├── Support/          SupportChat, SupportTemplate, Chat
│   │   │   └── System/           Dashboard, Setting, Staff, AdminLog, Log, System,
│   │   │                         Media, GeoIPLocation
│   │   └── Api/V1/               13 пользовательских контроллеров
│   ├── Middleware/       (без изменений)
│   ├── Requests/
│   │   ├── Admin/V1/{Users,Moderation,Content,Finance,Auth,Support,System}/
│   │   └── V1/                    пользовательские FormRequests
│   └── Resources/
│       └── Admin/V1/Users/        UserBadge, UserDetail, Photo,
│                                 AdminUserBadgeResource (новый)
├── Jobs/ Listeners/ Models/ Notifications/ Observers/   (без изменений)
├── Providers/
│   ├── AppServiceProvider.php        (544 → 85 строк)
│   └── RateLimitServiceProvider.php  (новый, ~500 строк)
├── Services/
│   ├── Admin/            AdminAuthService, AdminLogger (новый)
│   ├── Moderation/       CommentTreeService (новый)
│   └── ...               (остальные без изменений)
└── Support/              UserAgentParser (бывш. Helpers/)
```

---

## Этап 1. Документация → `docs/`

- `GEONAMES.md` → `docs/geo/geonames.md`
- `HORIZON|MAILPIT|PULSE|PURIFIER|REDIS|REVERB.md` → `docs/services/*.md`
- `Z_INFO/` → `docs/environment/` (`storage-sheme.txt` → `storage-scheme.txt`)
- Создан индекс `docs/README.md`; README в корне ссылается на docs.
- `AGENTS.md` / `CLAUDE.md` осознанно оставлены в корне: их ждёт AI-tooling по фиксированному пути.

## Этап 2. Валидация → FormRequests (49 конвертаций)

Все `$request->validate([...])` в контроллерах заменены на типизированные
FormRequests: 48 новых классов + переиспользован `UserListRequest`.
Размещение повторяет доменную структуру контроллеров
(`app/Http/Requests/Admin/V1/<Домен>/`, `app/Http/Requests/V1/`).
Правила скопированы дословно; нюансы `unique()->ignore()` решены через
`$this->route('id')` (паттерн существующего `BlogPostRequest`).

Остались инлайн: `SettingController`/`PageController` (Validator-фасад) и
`SubscriptionController::pay/renew` (ручной формат 422-ответа) — их конвертация
изменила бы формат ошибок, оставлено как есть.

## Этап 3. Ответы и дублирование

- `AdminUserBadgeResource` (флаги `withRole` / `withIsStaff`) заменил 8 разошедшихся
  приватных `formatUser()`/`formatUserShort()` (Dating, Report, Diary, DiaryComment,
  PhotoComment, Chat, Gift, SupportChat).
- `AdminController` — базовый класс с `notFound()` (все 29 админ-контроллеров
  наследуют его; BlogCategoryController ранее вообще не имел базового класса).
- `App\Services\Admin\AdminLogger::logCommentModeration()` заменил 2 копии `logAction()`.
- `App\Services\Moderation\CommentTreeService` заменил дубли `collectDescendantIds()`
  и `buildCascadeLog()` (BFS-обход дерева комментариев + каскадные логи).
- `formatChatListItem()` в Chat/SupportChat контроллерах оставлен — это разные
  структуры (2 участника vs partner+staff-поля), не дубликат.

## Этап 4+7. Неймспейсы и домены (Great Move, 66 файлов)

- Admin-контроллеры: `App\Http\Controllers\Api\Admin\*` → `App\Http\Controllers\Admin\V1\<Домен>\*`
- Пользовательские: `App\Http\Controllers\Api\*` → `App\Http\Controllers\Api\V1\*`
- Requests/Resources/Actions переехали в зеркальные доменные подпапки.
- `AdminAuthService` → `Services/Admin/`; `ContentFilterResult` → `App\DTO`;
  `UserAgentParser` → `App\Support`.
- Все ссылки в `routes/`, `app/`, `database/`, `tests/` переписаны скриптом с
  аудитом замен; URL эндпоинтов не менялись.

## Этап 5. Бизнес-логика → Actions (14 контроллеров, 26 транзакций)

Волна 1 (полное извлечение):
| Контроллер | Было | Стало | Action |
|---|---|---|---|
| DiaryController | 790 | 450 | ModerateDiaryAction + ManageDiaryRubricAction |
| DiaryCommentController | 678 | 363 | ModerateDiaryCommentAction |
| ReportController | 533 | 302 | ModerateReportAction (композиция ToggleUserBan + ModeratePhoto) |

Волна 2:
| Контроллер | Было | Стало | Action |
|---|---|---|---|
| PhotoCommentController | 972 | 385 | ModeratePhotoCommentAction (вкл. bulk) |
| StopWordController | 477 | 251 | ModerateStopWordAction |
| FraudAlertController | 376 | 265 | ResolveFraudAlertAction |
| BlockSignalController | 396 | 338 | ModerateBlockSignalAction |
| GiftController (admin) | 480 | 332 | ManageGiftAction |
| BlogPostController | 445 | 247 | ManageBlogPostAction |
| SupportChatController | 619 | 548 | SendSupportChatMessageAction |
| ChatController | 415 | 366 | ToggleChatLockAction |
| StaffController | 269 | 193 | ManageStaffAction |
| SettingController | 175 | 122 | ManageSettingsAction |
| GiftController (api) | 122 | 101 | SendGiftAction (App\Actions\Api) |

Итого 15 новых Action-классов (~4 000 строк бизнес-логики). В контроллерах не осталось
ни одного `DB::transaction` (проверено grep'ом) — только резолюция моделей, вызов
Action и формирование ответа. AdminLog-форматы, уведомления, кэш-ключи и тексты
ответов сохранены дословно.

## Этап 6. Провайдеры

`AppServiceProvider` (544 строки) разделён: SQL-дебаггер, observers и password-reset
остались (85 строк); все ~20 rate-limiter'ов переехали в новый
`App\Providers\RateLimitServiceProvider` (зарегистрирован в `bootstrap/providers.php`).

## Этап 8. Фабрики и тесты

- Фабрики: `UserFactory` (+states `admin/moderator/support/banned`), новые
  `PhotoFactory` (+`approved/rejected`), `SubscriptionPlanFactory` (+`vip/inactive`).
- Feature-тесты критических флоу (19 кейсов):
  - `tests/Feature/Admin/UserBanTest.php` — RBAC, permanent/temp/shadow, идемпотентность, AdminLog, уведомления.
  - `tests/Feature/Admin/PhotoModerationTest.php` — RBAC, approve (Queue::fake → ProcessApprovedPhoto), reject, системные причины, 404.
  - `tests/Feature/Api/SubscriptionPaymentTest.php` — создание транзакции, защита от дубля tier, репокупка после истечения, валидация.

Запуск: `composer install && php artisan test`. Требования: БД `testing`
(PostGIS-совместимая, как в compose), `CACHE_STORE=array`/`QUEUE_CONNECTION=sync`
уже заданы в phpunit.xml.

---

## Найденные и исправленные баги

1. **Висячий роут** `POST /api/admin/photo-comments/{id}/spam` → метод `markSpam`
   не существует (удалён ранее по «Варианту A»). Роут убран — раньше любой его вызов
   давал 500.
2. **BlogCategoryController не наследовал** `Controller` (голый класс) — подключён
   к `AdminController`.
3. **route('home') в SubscriptionController — ИСПРАВЛЕН (Пакет 1 «Деньги»)**: `pay`/`renew`/`success`
   подставляли `route('home')` как дефолт `return_url`, но именованного роута `home`
   в API-приложении нет → `RouteNotFoundException` при запросе без `return_url`.
   Заменён на `config('app.frontend_url')` (главная SPA).
4. **Пакет 1 «Деньги и целостность» (ШАГ 2)** — закрыты А3–А6, Б3–Б5, В5, В6, Г3:
   - `ProcessSyncJob`: план подписки проверяется ДО `markAsSuccess()` (раньше при
     удалённом плане — success + «оплата успешна» без выдачи);
   - `GrantSubscriptionAction` (новый): единая выдача/продление подписки с
     `lockForUpdate` — закрыты гонки двойного renew и TOCTOU покупки
     (проверка «тот же tier» была только в контроллере);
   - `RevokePurchasedItems`: откат продления по `meta.renewed_*` (раньше renewal-
     возврат не откатывал продление), ревок кредитов через условный `spendCredits`
     (раньше decrement падал на CHECK credits >= 0), листенер — `ShouldQueueAfterCommit`;
   - банк (MockAcquiringService) вызывается ВНЕ DB-транзакций (refund/checkStatus/approvePayment);
   - `retry_after` Redis-очереди 90 → 600 с (был меньше таймаутов job'ов 120–300с
     → дубли рассылок);
   - compose.yaml: добавлены сервисы `horizon`, `scheduler`, `mailpit` (раньше jobs
     и cron-задачи в docker-окружении не выполнялись вообще);
   - идемпотентность оплаты: заголовок `Idempotency-Key` + анти-даблклик (pending
     по тому же плану/номиналу моложе 60 сек — возвращается существующая транзакция);
   - планировщик: `subscriptions:process` с `onOneServer()`, атомарная метка
     `expires_notified_at`, в очередь передаётся ID вместо «слепка» модели
     (SendSubscribeExpiringNotification), `user:subscriptions:cancel` фильтрует
     по колонке `tier` (раньше `type` роняло команду);
   - payment-jobs: `$tries`/`$backoff`/`failed()` с critical-логом (зависшие pending
     теперь видны);
   - лок `transaction-sync-{id}` корректно освобождается (владелец передаётся в job;
     раньше `restoreLock(name, 60)` передавал TTL как owner — release был no-op).

5. **Пакет 5 «Архитектура, консистентность и realtime» (ШАГ 2, финальный)** —
   закрыты рекомендации №1–№3, №6, №8 и №9 аудита (частично №7):
   - realtime: 7 чат-событий переведены с ShouldBroadcastNow (синхронный
     Reverb-вызов внутри HTTP-запроса — главный риск подвисания API чатов)
     на ShouldBroadcastAfterCommit + очередь `broadcasts`: job уходит в
     очередь и только после коммита транзакции;
   - MessageObserver: минус до 5 лишних SELECT на каждое сообщение
     (Chat::find ×2, User::find(sender) ×3 → по одному);
   - единый конвейер ошибок: глобальный 404-рендер для API (закрыта утечка
     «No query results for model [App\…]»), доменное исключение
     InsufficientCreditsException (подарки: 402 только для «мало кредитов»,
     внутренние ошибки → 500 с фиксированным текстом), закрыты утечки
     getMessage() в System/Setting контроллерах;
   - AuthController: 8 инлайн-валидаций → FormRequests с побайтовым
     сохранением обоих legacy-форматов 422 ({"errors"} и
     {"message": "The given data was invalid.", "errors"}); хардкод
     dating_goals → enum DatingGoal; pay/renew дедуплицированы
     (PaySubscriptionRequest + createPaymentTransaction); Setting/Page
     контроллеры → FormRequests (AdminJsonRequest, формат {"errors"});
     /settings/theme из замыкания → SettingsController::updateTheme;
   - кэш справочников 10 минут: тарифы подписок и каталог подарков,
     инвалидация в моделях (паттерн Interest);
   - рекомендация №7 (сессии на Redis) — НЕ переключено: найдена скрытая
     зависимость (ban-logout и kill-sessions работают прямым SQL по таблице
     sessions в 5 местах — при redis-драйвере забаненный юзер оставался бы
     залогиненным). Задокументировано в .env.example, нужен реестр сессий;
   - +17 тестов: форматы 422 auth-флоу, тема, контракт realtime-событий.
   Осознанно оставлено: разбор UserListingsController и слой
   Resources/Api/V1/Web (требуют staging-верификации форматов),
   /api/v1/ и superlike (продуктовые решения).

## Осознанно оставленное

- `.phpstorm.meta.php` содержит ссылки на старые FQCN — регенерируй
  `php artisan ide-helper:generate` после `composer install`.
- `support-chats` markAsRead/archive/unarchive остались в контроллере
  (нетранзакционные, один класс сущности) — кандидат на извлечение при следующем проходе.
- Инлайн-валидация `POST /api/settings/theme` (роут-замыкание) — FormRequest
  к замыканию не привязать без выноса в контроллер.

## Как развернуть

```bash
composer install          # + composer dump-autoload (неймспейсы уже PSR-4-валидны)
php artisan test          # 19 новых флоу-тестов + examples
vendor/bin/pint           # опционально: нормализация стиля импортов
php artisan ide-helper:generate   # обновить .phpstorm.meta.php
```

## Дополнение (фронд-часть 2): пер-юзер листинги для табов админки

Для страницы пользователя `/users/{id}` админки добавлены 13 read-only
эндпоинтов (все GET, без мутаций — действия остаются в глобальных разделах,
где throttle и RBAC):

| Эндпоинт | Контроллер | Роли |
|---|---|---|
| `GET /users/{user}/photo-comments` | UserListingsController::photoComments | admin, moderator |
| `GET /users/{user}/diaries` | ::diaries | admin, moderator |
| `GET /users/{user}/diary-comments` | ::diaryComments | admin, moderator |
| `GET /users/{user}/dating` | ::dating (swipes + matches, две пагинации) | admin, moderator |
| `GET /users/{user}/chats` | ::chats | admin, moderator |
| `GET /users/{user}/events` | ::events | admin, moderator |
| `GET /users/{user}/transactions` | ::transactions | admin |
| `GET /users/{user}/gifts` | ::gifts (sent + received) | admin |
| `GET /users/{user}/broadcasts` | ::broadcasts (персональные) | admin |
| `GET /users/{user}/admin-logs` | ::adminLogs (все действия, не только баны) | admin |
| `GET /users/{user}/cards` | ::cards (маскированные, без токенов) | admin |
| `GET /users/{user}/promocodes` | ::promocodes | admin |
| `GET /users/{user}/auth-logs` | ::authLogs | admin |

Новые файлы:

- `app/Http/Controllers/Admin/V1/Users/UserListingsController.php` —
  все 13 методов в стиле UserController (404-гард, плоский пагинатор,
  accessor-безопасные селекты, форматы 1:1 с глобальными списками).
- `app/Http/Requests/Admin/V1/Users/`: UserPhotoCommentsRequest,
  UserDiariesRequest, UserDiaryCommentsRequest, UserDatingRequest,
  UserTransactionsRequest, UserGiftsRequest, UserAuthLogsRequest
  (все наследуют UserListRequest).
- `routes/admin.php` — маршруты в группах по RBAC (см. таблицу).

Изменений в существующих контроллерах/ответах НЕТ — чисто аддитивно.

---

## Дополнение (ШАГ 2, ПАКЕТ 6): разбор UserListingsController + реестр сессий

Дата: 2026-11-16. Закрывает последние инженерные хвосты аудита Шага 1:
рекомендацию №4 (разбор монолита UserListingsController, 1477 строк) и
блокер рекомендации №7 (реестр сессий — Пакет 5 задокументировал его как
обязательное условие переезда сессий на Redis). Инвариант всех пакетов
сохранён: URL эндпоинтов и форматы JSON-ответов не менялись.

### 1. Разбор UserListingsController (рекомендация №4)

Монолит (1477 строк, 14 эндпоинтов) разделён на 4 доменных контроллера +
абстрактный базовый класс с общим тулкитом:

| Файл (app/Http/Controllers/Api/V1/Admin/Main/) | Эндпоинты |
|---|---|
| UserTabListingController (abstract) | тулкит: userNotFoundResponse, badgeUser, paginatorToArray, USER_BADGE_COLUMNS/PHOTO_CONTEXT_COLUMNS |
| UserContentListingsController | photos, photoComments, diaries, diaryComments (moderator+admin) |
| UserActivityListingsController | dating, chats, events (moderator+admin) |
| UserFinanceListingsController | finances, gifts, cards, promocodes (admin only) |
| UserAuditListingsController | broadcasts, adminLogs, authLogs (admin only) |

- `routes/admin.php` — те же URL и имена методов, другие контроллеры;
  RBAC-группы не тронуты (проверено тестом moderator: контент-табы 200,
  финансы/аудит 403).
- Осознанное отклонение от буквы рекомендации №4 («перейти на хелпер
  paginated()»): формат пагинации пер-юзер табов исторически ПЛОСКИЙ
  (`{data, total, current_page, ...}` в корне), а paginated() даёт
  meta-обёртку — переход ломал бы админ-фронт. Разбор сделан с сохранением
  форматов.
- Верификация форматов — golden-контрактом
  `tests/Feature/Admin/UserListingsContractTest.php` (14 эндпоинтов +
  404-гард + RBAC): тест фиксирует наборы ключей СТАРОГО кода; процедура
  приёмки — прогнать на ветке ДО разбора и ПОСЛЕ, оба прогона обязаны
  быть зелёными (детали — в шапке теста).

### 2. Реестр сессий (блокер рекомендации №7)

`App\Services\V1\SessionRegistry` — единая точка принудительного logout,
driver-aware:

- **SESSION_DRIVER=database (сейчас)**: источник истины — таблица sessions,
  поведение побайтово совпадает с прежним прямым SQL (чтение/DELETE в тех
  же местах, те же count'ы и AdminLog-форматы). Redis при этом ведётся
  параллельно (подготовка к переезду).
- **SESSION_DRIVER=redis (после переезда)**: источник истины — Redis-реестр
  `sreg:user:{id}` (SET id сессий) + `sreg:meta:{sid}` (HASH user_id/ip/
  user_agent/last_activity), уничтожение через `session()->getHandler()
  ->destroy()` (контракт SessionHandlerInterface — работает для любого
  драйвера). Ленивая чистка призраков: TTL ключей = lifetime + 10 мин,
  фильтр по свежести last_activity + проверка `handler->read()`.
- Отказоустойчивость: ВСЕ Redis-опции в try/catch — недоступность Redis
  никогда не роняет запрос и не ломает бан/logout (в database-режиме
  реестр вспомогательный). Первая ошибка — warning в лог, дальше тихо.
- Выключатель `SESSION_REGISTRY_ENABLED` (config/session.php): false —
  redis-часть отключается (тесты: phpunit.xml; авария — рубильник).

Переведены все 6 мест прямого SQL по таблице sessions:

| Место | Было | Стало |
|---|---|---|
| ManageUserSessionsAction::getUserSessions | SELECT по таблице | registry->allFor() |
| ::killSession | SELECT + DELETE | registry->destroyOne() (внутри прежней транзакции с AdminLog) |
| ::killAllSessions | DELETE + count | registry->destroyAllFor() (внутри транзакции) |
| ToggleUserBanAction::ban | DELETE внутри транзакции | registry->destroyAllFor() внутри той же транзакции |
| User::deleted (soft-delete) | DELETE | app(SessionRegistry::class)->destroyAllFor() |
| Admin AuthController::logoutEverywhere | DELETE … id != текущей | registry->destroyAllFor(userId, except: текущая) |

Реестр ведут:

- `App\Http\Middleware\V1\TrackUserSession` (добавлен в web-группу
  bootstrap/app.php — покрывает и юзер-SPA, и админку): touch ПОСЛЕ
  контроллера (логин уже сделал session()->regenerate() → в реестре живой
  id; поэтому НЕ Login-листенер), троттлинг 1 раз / 60 с на сессию через
  флаг в данных сессии (0 дополнительных запросов).
- `App\Listeners\V1\RemoveSessionFromRegistry` (Logout-событие): мгновенно
  убирает сессию из реестра (Logout стреляет ДО invalidate(), id ещё жив).
  Авто-дискаверится по type-hint, как RevokePurchasedItems.
- Чистка реестра при банах/kill'ах — отложенно через DB::afterCommit,
  если вызов внутри транзакции: Redis-задержки не растягивают транзакции.

После этого `SESSION_DRIVER=redis` становится безопасным (инструкция по
приёмке на staging — в .env.example, раздел «ПЕРЕЕЗД НА REDIS»).

### 3. Тесты

- `tests/Feature/Admin/UserListingsContractTest.php` — 16 кейсов: golden
  контракт всех 14 табов (top-level ключи + контракты строк), 404-гард,
  RBAC moderator/admin.
- `tests/Feature/Admin/UserSessionsTest.php` — 8 кейсов: список (формат
  строки 1:1 + сортировка), kill (владение, AdminLog), kill-all (count,
  пустой кейс), бан → logout, soft-delete → logout, logout-everywhere
  (текущая сессия выживает).
- `tests/Unit/SessionRegistryTest.php` — 8 кейсов: database-стратегия
  (сортировка, владение, destroy + meta, except), деградация без Redis,
  выключатель.

### Миграции БД: не требуются

Все правки — код, роуты и конфиги. Схема БД не менялась.
