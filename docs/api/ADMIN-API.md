# Спецификация Admin API (v1)

База: `/api/admin/*` · Формат: JSON · Аутентификация: Sanctum (SPA-cookie, guard `web`)

> Полный каталог эндпоинтов — в `routes/admin.php` (подключается из
> `routes/api.php` под префиксом `admin`). Этот документ — карта разделов,
> контрактов и правил доступа для фронтендера админки.

---

## 1. Аутентификация

| Метод | Путь | Описание |
| --- | --- | --- |
| POST | `/login` | Логин стаффа. Throttle: `admin-login` (5/мин email+IP) + `admin-login-by-email` (10/15 мин) |
| GET | `/me` | Текущий профиль (все staff) |
| POST | `/logout` | Выход |
| POST | `/logout-everywhere` | Завершить ВСЕ другие свои сессии (кроме текущей) |

Логин публичный, всё остальное — `auth:sanctum` + `admin.role`.

## 2. Матрица ролей (3 группы роутов)

| Группа | Middleware | Роли | Разделы |
| --- | --- | --- | --- |
| 1 | `admin.role` | admin, moderator, support | dashboard, users (чтение), support-chats, support-templates, fraud-alerts, block-signals |
| 2 | `admin.role:admin,moderator` | admin, moderator | модерация: photos, photo-comments, dating, reports, diary, diary-comments, chats, stop-words, баны/действия над юзерами |
| 3 | `admin.role:admin` | только admin | staff, interests, settings, pages, media, blog, geoip, audit, broadcasts, transactions, subscription-plans, gifts |

Ошибки доступа: 401 `{"message": "Неавторизован."}` (гость / забаненный staff),
403 `{"message": "Доступ запрещён. Требуется роль сотрудника."}` /
`…роль: admin, moderator` (role-matrix покрыта тестом `AdminCrudSmokeTest`).

Забаненный staff выкидывается ИЗ СЕССИИ на любом запросе (`AdminRoleMiddleware`).

## 3. Соглашения о форматах

| Формат | Вид | Где |
| --- | --- | --- |
| Пагинация | `{ "data": [...], "meta": { current_page, last_page, per_page, total, from, to } }` | все списки (`AdminController::paginated`) |
| Сообщение | `{ "message": "…" }` | все мутации (`AdminController::message`) |
| 404 | `{ "message": "<Сущность> не найдена" }` | route-binding / явный 404 |
| Валидация | `{ "errors": { "поле": ["…"] } }` + 422 | FormRequests |
| Модель не найдена (API) | `{ "message": "Запись не найдена." }` | конвейер 404 из `bootstrap/app.php` (без FQCN моделей) |

Аудит: значимые мутации пишут `AdminLog` (`action`, `before/after`,
`admin_id`, `participants`) — журнал в разделе «Аудит».

## 4. Дашборд

| Метод | Путь | Описание |
| --- | --- | --- |
| GET | `/dashboard/stats` | агрегаты по ролям (данные делятся по роли внутри контроллера) |

## 5. Пользователи (главный раздел)

| Метод | Путь | Группа | Описание |
| --- | --- | --- | --- |
| GET | `/users` | 1 | список + фильтры (`UserIndexRequest`) |
| GET | `/users/stats` | 1 | счётчики |
| GET | `/users/{user}` | 1 | детальная карточка |
| GET | `/users/{user}/ban-history` | 1 | история банов |
| POST | `/users/{userId}/ban` | 2 | бан (type/reason/days → сессии убиваются) |
| POST | `/users/{userId}/unban` | 2 | разбан |
| POST | `/users/{userId}/restore` | 2 | восстановить soft-deleted |
| DELETE | `/users/{userId}` | 2 | удаление (soft) |
| POST | `/users/{user}/profile/clear-field` | 2 | очистить поле профиля с уведомлением |
| GET | `/users/{user}/reports` / `/blocks` | 1 | жалобы/блокировки |
| POST | `/users/{user}/blocks/unblock` | 2 | разблокировать |

### 5.1. Табы пользовательской страницы (разбор Пакета 6)

| Метод | Путь | Контроллер |
| --- | --- | --- |
| GET | `/users/{user}/photos`, `/photo-comments`, `/diaries`, `/diary-comments` | `UserContentListingsController` |
| GET | `/users/{user}/dating`, `/chats`, `/events` | `UserActivityListingsController` |
| GET | `/users/{user}/finances`, `/gifts`, `/cards`, `/promocodes` | `UserFinanceListingsController` |
| GET | `/users/{user}/broadcasts`, `/admin-logs`, `/auth-logs` | `UserAuditListingsController` |

Форматы зафиксированы golden-контрактом `UserListingsContractTest`.

### 5.2. Сессии пользователя (реестр Пакета 6)

| Метод | Путь | Описание |
| --- | --- | --- |
| GET | `/users/{user}/sessions` | «Активные устройства»: `[{id, ip, device, browser, is_mobile, last_activity, exact_time}]`, сортировка по свежести |
| POST | `/users/{user}/sessions/kill` | kill одной (защита владения WHERE user_id, иначе 422) |
| POST | `/users/{user}/sessions/kill-all` | kill всех |

Источник истины — `SessionRegistry` (driver-aware: database/redis).

## 6. Безопасность (антифрод)

| Метод | Путь | Описание |
| --- | --- | --- |
| GET | `/fraud-alerts` + `/fraud-alerts/counts` | очередь фрод-алертов |
| POST | `/fraud-alerts/{alert}/resolve-and-ban` | решить + забанить |
| POST | `/fraud-alerts/{alert}/resolve-with-warning` | решить с предупреждением |
| POST | `/fraud-alerts/{alert}/false-positive` | ложное срабатывание |
| GET | `/block-signals` + `/block-signals/counts` | сигналы блокировок |
| GET | `/block-signals/{userId}/blockers` | кто блокирует юзера |
| POST | `/block-signals/{userId}/ban` | бан по сигналу |

## 7. Модерация (группа 2)

- **Фото**: `GET /photos`, `GET /photos/counts`, `POST /photos/user/{userId}/approve-all|reject-all`,
  `POST /photos/{id}/approve|reject|set-primary|soft-delete|restore`, `DELETE /photos/{id}`.
- **Комментарии к фото**: `GET /photo-comments(+/counts)`, bulk-действия
  `approve-all|reject-all`, `approve-remaining|reject-remaining` по фото,
  поштучно `approve|reject|restore|soft-delete`, `DELETE /{id}`.
- **Свайпы/мэтчи**: `GET /dating(+/counts)`, `DELETE /dating/swipes/{id}`,
  `DELETE /dating/matches/{id}`, `POST /dating/matches/{id}/restore`.
- **Жалобы**: `GET /reports(+/counts)`, `POST /reports/{id}/resolve|reject|reopen`,
  `POST /reports/user/{userId}/ban`, `POST /reports/photo/{photoId}/reject`.
- **Дневники**: `GET /diary(+/counts)` (tab=diaries|rubrics), `GET /diary/{id}`,
  `POST /diary/{id}/approve|reject|unpublish|settings|soft-delete|restore`,
  `DELETE /diary/{id}` (force). Причины отказа — enum `DiaryRejectReason`
  (spam/porn/scam/insult/other).
- **Рубрики дневников**: `GET /diary/rubrics/check-name`, `POST/PUT/DELETE
  /diary/rubrics(/{id})`, `POST /diary/rubrics/{id}/toggle`.
- **Комментарии к дневникам**: `GET /diary-comments(+/counts)`, поштучные
  `approve|reject|restore|soft-delete`, `DELETE /{id}`.
- **Чаты юзеров**: `GET /chats`, `GET /chats/{chatId}`, `POST /chats/{chatId}/toggle-lock`.
- **Стоп-слова**: `GET /stop-words(+/counts)`, `POST /stop-words` (bulk-store),
  `POST /stop-words/bulk`, `PUT /stop-words/{id}`, `POST /stop-words/{id}/toggle`,
  `DELETE /stop-words/{id}`.

## 8. Поддержка (группа 1)

| Метод | Путь | Описание |
| --- | --- | --- |
| GET | `/support-chats(+/templates)` | тикеты + шаблоны |
| GET | `/support-chats/{chatId}` | переписка |
| POST | `/support-chats/{chatId}/mark-as-read|archive|unarchive|send` | действия |
| POST | `/support-chats/start/{userId}` | начать тикет с юзером |
| GET | `/support-templates(+/categories, /{id})` | библиотека шаблонов |

## 9. Система и CMS (только admin, группа 3)

- **Staff**: `GET /staff`, `GET /staff/search`, `POST /staff/{userId}/promote|demote`,
  `PATCH /staff/batch-update`. Founders (`APP_FOUNDERS`) защищены от понижения.
- **Интересы**: `GET/POST /interests/categories`, `PUT/DELETE /interests/categories/{category}`;
  `GET /interests(+/counts)`, `POST/PUT/DELETE /interests(/{interest})`,
  `POST /interests/{interest}/boost`, `POST /interests/bulk`.
- **Настройки**: `GET /settings/groups`, `GET/POST /settings`, `POST /settings/reset`.
- **Страницы**: CRUD `/pages` + `toggle-status`, `duplicate`, `POST /pages/bulk`.
- **Медиа**: `GET /media(+/counts)`, `POST /media` (upload), `POST /media/bulk-delete`,
  `GET/DELETE /media/{media}`. Порядок маршрутов: статические (`counts`) выше параметрических.
- **Блог**: категории `GET/POST/PUT/DELETE /blog/categories`; посты
  `GET /blog/posts(+/counts)`, `POST/PUT/DELETE /blog/posts(/{post})`,
  `duplicate|toggle|archive|restore|feature`, `POST /blog/posts/bulk`.
- **GeoIP**: `GET /geoip/locations(+/counts)`, `toggle-registration`, `toggle-feed`.
- **Аудит**: `GET /audit/logs`, `POST /audit/logs/refresh`.
- **Рассылки**: `GET /broadcasts(+/counts)`, `GET /broadcasts/search-users`,
  `POST /broadcasts`, `POST /broadcasts/bulk-delete`, `GET/PUT/DELETE /broadcasts/{broadcast}`,
  `POST …/send-now|duplicate` — отправка чанками через очередь.
- **Финансы**: `GET /transactions`, `GET /transactions/{id}`, `POST …/sync|refund`;
  планы подписок `GET/POST/PUT /subscription-plans`, `POST /subscription-plans/{id}/toggle`;
  каталог подарков `GET/POST/PUT/DELETE /gifts(/{id})`.

## 10. Тесты как живой контракт

Поверх этой спецификации поведение зафиксировано тестами (13 файлов,
`php artisan test`):

| Раздел | Тест |
| --- | --- |
| Баны, сессии, листинги, smoke 40+ эндпоинтов | `AdminCrudSmokeTest`, `UserBanTest`, `UserSessionsTest`, `UserListingsContractTest` |
| Модерация фото / дневников | `PhotoModerationTest`, `DiaryModerationTest` |
| Пользовательские флоу | `SwipeMatchTest`, `GiftFlowTest`, `SubscriptionPaymentTest`, `AuthValidationTest`, `ThemeSettingsTest` |
| Инфраструктура | `SessionRegistryTest`, `RealtimeEventsTest` |

CI (`.github/workflows/ci.yml`) прогоняет их на Postgres 16 + PostGIS на каждый push.
