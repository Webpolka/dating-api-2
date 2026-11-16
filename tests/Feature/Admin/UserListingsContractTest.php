<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GOLDEN-КОНТРАКТ пер-юзер листингов (ПАКЕТ 6, рекомендация №4 аудита Шага 1).
 *
 * Разбор монолита UserListingsController (1477 строк) на 4 доменных
 * контроллера обязан сохранить форматы ответов ПОБАЙТОВО. Этот тест —
 * «золотой файл», зафиксированный по СТАРОМУ коду (до разбора).
 *
 * ПРОЦЕДУРА ПРИЁМКИ НА STAGING:
 *   1. Прогнать этот тест на ВЕТКЕ ДО разбора (UserListingsController) — зелёный.
 *   2. Прогнать на ветке ПОСЛЕ разбора (UserContent/Activity/Finance/Audit
 *      ListingsController) — зелёный. Совпадение = форматы не уехали.
 *
 * Тест привязан к СТРУКТУРЕ (наборы ключей), а не к данным: работает на любом
 * датасете staging. На пустой базе проверяются top-level контракты (главное —
 * форма пагинации и блоки), на прогретой — контракты строк.
 *
 * Требования к окружению: тестовая БД (DB_DATABASE=testing),
 * PostGIS-совместимый сервер (миграция 0001 создаёт расширение).
 */
class UserListingsContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** Юзер, чью страницу (/users/{id}) открываем. */
    private User $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        // Датасет staging произволен — тест структурный. Берём любого юзера
        // (в CI фабричного, на staging — последнего созданного с данными).
        $this->subject = User::query()->orderByDesc('id')->first() ?? User::factory()->create();
    }

    // ============================================
    // Хелперы контракта (чистые массивы — без интринсиков AssertableJson)
    // ============================================

    /** Точное множество ключей объекта (порядок не важен). */
    private function assertKeys(array $payload, array $keys, string $context): void
    {
        $actual = array_keys($payload);
        sort($actual);

        $expected = $keys;
        sort($expected);

        $this->assertSame(
            $expected,
            $actual,
            "Контракт {$context}: набор ключей изменился (лишние/пропавшие ключи = поломанный фронт).",
        );
    }

    /** «Плоский» пагинатор пер-юзер табов (НЕ meta-формат AdminController::paginated()!). */
    private function assertFlatPaginator(array $payload, string $context): void
    {
        $this->assertKeys($payload, [
            'data', 'total', 'current_page', 'last_page', 'per_page', 'from', 'to',
        ], "{$context} (плоский пагинатор)");
    }

    /** Контракт строк списка: если данные есть — сверяем ключи первой строки. */
    private function assertRowKeys(array $rows, array $keys, string $context): void
    {
        if ($rows === []) {
            $this->addToAssertionCount(1); // пустой список — top-level контракт уже проверен

            return;
        }

        $actual = array_keys($rows[0]);
        sort($actual);

        $expected = $keys;
        sort($expected);

        $this->assertSame($expected, $actual, "Контракт {$context} (строка списка).");
    }

    /** Строка «бейджа» юзера (AdminUserBadgeResource). */
    private function assertBadgeKeys(?array $badge, string $context): void
    {
        if ($badge === null) {
            return; // опциональная связь может отсутствовать — это тоже контракт
        }

        $this->assertKeys($badge, [
            'id', 'name', 'email', 'avatar_url', 'is_online', 'status',
            'deleted_at', 'has_active_premium', 'has_active_vip', 'is_verified',
        ], "{$context} (бейдж юзера)");
    }

    /** Хелпер запроса контракта. Имя НЕ get(): в Laravel 13 TestCase::get()
     *  публичен — приватный get() в дочернем классе даёт фатал «Access level
     *  … must be public» при загрузке тест-класса. */
    private function fetchContract(string $url): array
    {
        return $this->actingAs($this->admin, 'web')
            ->getJson($url)
            ->assertOk()
            ->json();
    }

    // ============================================
    // 404-гард
    // ============================================

    public function test_unknown_user_returns_unified_404(): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson('/api/admin/users/99999999/photos')
            ->assertStatus(404)
            ->assertJson(['message' => 'Пользователь не найден']);
    }

    // ============================================
    // Таб «Фото» (UserContentListingsController::photos)
    // ============================================

    public function test_photos_tab_contract(): void
    {
        $payload = $this->fetchContract("/api/admin/users/{$this->subject->id}/photos");

        $this->assertKeys($payload, ['albums', 'counts'], 'photos: top-level');
        $this->assertKeys(
            $payload['counts'],
            ['albums', 'photos_total', 'photos_primary', 'photos_intimate'],
            'photos: counts',
        );

        $this->assertRowKeys($payload['albums'], [
            'id', 'name', 'description', 'is_default', 'is_private',
            'photos_count', 'created_at', 'deleted_at', 'photos',
        ], 'photos: альбом');

        if ($payload['albums'] !== []) {
            $this->assertRowKeys($payload['albums'][0]['photos'], [
                'id', 'type', 'is_primary', 'is_intimate', 'position',
                'title', 'description', 'created_at',
                'thumb_url', 'medium_url', 'large_url', 'original_url',
            ], 'photos: фото в альбоме');
        }
    }

    // ============================================
    // Таб «Комментарии к фото» (made/received)
    // ============================================

    public function test_photo_comments_tab_contract(): void
    {
        $payload = $this->fetchContract("/api/admin/users/{$this->subject->id}/photo-comments");

        $this->assertKeys($payload, ['made', 'received', 'counts'], 'photo-comments: top-level');
        $this->assertKeys($payload['counts'], ['made', 'received'], 'photo-comments: counts');

        foreach (['made', 'received'] as $column) {
            $this->assertFlatPaginator($payload[$column], "photo-comments: {$column}");
            $this->assertRowKeys($payload[$column]['data'], [
                'id', 'photo_id', 'parent_id', 'content', 'status', 'reject_reason',
                'moderated_by', 'moderated_at', 'created_at', 'updated_at', 'trashed',
                'is_pinned', 'likes_count', 'replies_count', 'user', 'photo',
            ], "photo-comments: {$column} строка");
        }
    }

    // ============================================
    // Таб «Дневники»
    // ============================================

    public function test_diaries_tab_contract(): void
    {
        $payload = $this->fetchContract("/api/admin/users/{$this->subject->id}/diaries");

        // Плоский пагинатор + фильтры-эхо в КОРНЕ (не в meta!).
        $this->assertKeys($payload, [
            'data', 'total', 'current_page', 'last_page', 'per_page', 'from', 'to',
            'rubrics', 'rubric', 'search',
        ], 'diaries: top-level');

        $this->assertRowKeys($payload['data'], [
            'id', 'title', 'status', 'reject_reason', 'moderated_by', 'moderated_at',
            'published_at', 'created_at', 'updated_at', 'trashed', 'is_comments_enabled',
            'views_count', 'comments_count', 'likes_count', 'user', 'diary_rubric',
        ], 'diaries: строка');

        foreach ($payload['rubrics'] as $rubric) {
            $this->assertKeys($rubric, [
                'id', 'name', 'is_active', 'is_personal', 'user_id',
            ], 'diaries: рубрика');
        }
    }

    // ============================================
    // Таб «Комментарии к дневникам»
    // ============================================

    public function test_diary_comments_tab_contract(): void
    {
        $payload = $this->fetchContract("/api/admin/users/{$this->subject->id}/diary-comments");

        $this->assertKeys($payload, ['made', 'received', 'counts'], 'diary-comments: top-level');
        $this->assertKeys($payload['counts'], ['made', 'received'], 'diary-comments: counts');

        foreach (['made', 'received'] as $column) {
            $this->assertFlatPaginator($payload[$column], "diary-comments: {$column}");
            $this->assertRowKeys($payload[$column]['data'], [
                'id', 'diary_id', 'parent_id', 'content', 'status', 'reject_reason',
                'moderated_by', 'moderated_at', 'created_at', 'updated_at', 'trashed',
                'likes_count', 'replies_count', 'user', 'diary',
            ], "diary-comments: {$column} строка");
        }
    }

    // ============================================
    // Таб «Знакомства» (swipes + matches)
    // ============================================

    public function test_dating_tab_contract(): void
    {
        $payload = $this->fetchContract("/api/admin/users/{$this->subject->id}/dating");

        $this->assertKeys($payload, ['swipes', 'matches', 'counts'], 'dating: top-level');
        $this->assertKeys(
            $payload['counts'],
            ['swipes', 'likes', 'dislikes', 'superlikes', 'matches'],
            'dating: counts',
        );

        $this->assertFlatPaginator($payload['swipes'], 'dating: swipes');
        $this->assertFlatPaginator($payload['matches'], 'dating: matches');

        $this->assertRowKeys($payload['swipes']['data'], [
            'id', 'type', 'rewinded', 'created_at', 'targetUser',
        ], 'dating: свайп');

        $this->assertRowKeys($payload['matches']['data'], [
            'id', 'status', 'unmatched_by', 'unmatched_at', 'created_at', 'user1', 'user2',
        ], 'dating: мэтч');
    }

    // ============================================
    // Таб «Чаты»
    // ============================================

    public function test_chats_tab_contract(): void
    {
        $payload = $this->fetchContract("/api/admin/users/{$this->subject->id}/chats");

        $this->assertFlatPaginator($payload, 'chats');

        $this->assertRowKeys($payload['data'], [
            'participant_id', 'chat', 'partner', 'participant_state',
        ], 'chats: строка');

        if ($payload['data'] !== [] && $payload['data'][0]['chat'] !== null) {
            $this->assertKeys($payload['data'][0]['chat'], [
                'id', 'type', 'is_locked', 'last_message_at', 'last_message',
            ], 'chats: chat');

            $this->assertKeys($payload['data'][0]['participant_state'], [
                'unread_count', 'is_hidden', 'is_muted', 'is_blocked', 'last_read_at',
            ], 'chats: participant_state');
        }
    }

    // ============================================
    // Таб «События»
    // ============================================

    public function test_events_tab_contract(): void
    {
        $payload = $this->fetchContract("/api/admin/users/{$this->subject->id}/events");

        $this->assertFlatPaginator($payload, 'events');
        $this->assertRowKeys($payload['data'], [
            'id', 'type', 'properties', 'created_at', 'meta',
        ], 'events: строка');

        if ($payload['data'] !== []) {
            $this->assertKeys($payload['data'][0]['meta'], ['title', 'icon', 'color'], 'events: meta');
        }
    }

    // ============================================
    // Таб «Финансы» (admin only)
    // ============================================

    public function test_finances_tab_contract(): void
    {
        $payload = $this->fetchContract("/api/admin/users/{$this->subject->id}/finances");

        $this->assertKeys(
            $payload,
            ['balance', 'statuses', 'transactions', 'subscriptions'],
            'finances: top-level',
        );
        $this->assertKeys($payload['balance'], [
            'credits', 'superlikes_remaining', 'superlikes_reset_at',
        ], 'finances: balance');
        $this->assertKeys($payload['statuses'], [
            'has_active_premium', 'premium_expires_at', 'has_active_vip', 'vip_expires_at',
        ], 'finances: statuses');

        $this->assertFlatPaginator($payload['transactions'], 'finances: transactions');
        $this->assertRowKeys($payload['transactions']['data'], [
            'id', 'user_id', 'amount', 'formatted_amount', 'amount_class', 'currency',
            'type', 'type_badge', 'status', 'status_badge', 'provider',
            'provider_transaction_id', 'credits_amount', 'created_at', 'updated_at',
        ], 'finances: транзакция');

        $this->assertFlatPaginator($payload['subscriptions'], 'finances: subscriptions');
        $this->assertRowKeys($payload['subscriptions']['data'], [
            'id', 'plan_id', 'transaction_id', 'tier', 'status', 'status_badge',
            'is_auto_renew', 'starts_at', 'ends_at', 'created_at', 'updated_at', 'plan',
        ], 'finances: подписка');
    }

    // ============================================
    // Таб «Подарки» (admin only)
    // ============================================

    public function test_gifts_tab_contract(): void
    {
        $payload = $this->fetchContract("/api/admin/users/{$this->subject->id}/gifts?filter=all");

        $this->assertKeys($payload, [
            'data', 'total', 'current_page', 'last_page', 'per_page', 'from', 'to',
            'counts', 'filter',
        ], 'gifts: top-level');
        $this->assertKeys($payload['counts'], ['all', 'sent', 'received'], 'gifts: counts');

        $this->assertRowKeys($payload['data'], [
            'id', 'direction', 'snapshot_name', 'snapshot_image_url', 'snapshot_price',
            'message', 'is_private', 'is_read', 'is_hidden', 'created_at',
            'sender', 'receiver', 'gift',
        ], 'gifts: строка');

        if ($payload['data'] !== []) {
            $this->assertBadgeKeys($payload['data'][0]['sender'], 'gifts: sender');
            $this->assertBadgeKeys($payload['data'][0]['receiver'], 'gifts: receiver');

            if ($payload['data'][0]['gift'] !== null) {
                $this->assertKeys($payload['data'][0]['gift'], [
                    'id', 'name', 'image_url', 'type', 'category', 'category_label', 'category_color',
                ], 'gifts: gift');
            }
        }
    }

    // ============================================
    // Таб «Карты» (admin only)
    // ============================================

    public function test_cards_tab_contract(): void
    {
        $payload = $this->fetchContract("/api/admin/users/{$this->subject->id}/cards");

        $this->assertFlatPaginator($payload, 'cards');
        $this->assertRowKeys($payload['data'], [
            'id', 'gateway', 'card_type', 'last4', 'expiry_month', 'expiry_year',
            'is_active', 'is_default', 'is_expired', 'trashed', 'created_at', 'deleted_at',
        ], 'cards: строка');
    }

    // ============================================
    // Таб «Промокоды» (admin only)
    // ============================================

    public function test_promocodes_tab_contract(): void
    {
        $payload = $this->fetchContract("/api/admin/users/{$this->subject->id}/promocodes");

        $this->assertFlatPaginator($payload, 'promocodes');
        $this->assertRowKeys($payload['data'], [
            'id', 'applied_discount', 'created_at', 'promo_code', 'transaction',
        ], 'promocodes: строка');
    }

    // ============================================
    // Таб «Рассылки» (admin only)
    // ============================================

    public function test_broadcasts_tab_contract(): void
    {
        $payload = $this->fetchContract("/api/admin/users/{$this->subject->id}/broadcasts?filter=all");

        $this->assertKeys($payload, [
            'data', 'total', 'current_page', 'last_page', 'per_page', 'from', 'to',
            'counts', 'filter',
        ], 'broadcasts: top-level');
        $this->assertKeys($payload['counts'], ['all', 'in_app', 'email', 'push'], 'broadcasts: counts');

        $this->assertRowKeys($payload['data'], [
            'id', 'title', 'message', 'type', 'status', 'progress', 'sent_count',
            'failed_count', 'total_recipients', 'scheduled_at', 'sent_at', 'started_at',
            'created_at', 'audience_label', 'admin',
        ], 'broadcasts: строка');
    }

    // ============================================
    // Таб «Логи админов» (admin only)
    // ============================================

    public function test_admin_logs_tab_contract(): void
    {
        $payload = $this->fetchContract("/api/admin/users/{$this->subject->id}/admin-logs");

        $this->assertFlatPaginator($payload, 'admin-logs');
        $this->assertRowKeys($payload['data'], [
            'id', 'action', 'before', 'after', 'ip_address', 'created_at', 'meta', 'admin',
        ], 'admin-logs: строка');
    }

    // ============================================
    // Таб «История входов» (admin only)
    // ============================================

    public function test_auth_logs_tab_contract(): void
    {
        $payload = $this->fetchContract("/api/admin/users/{$this->subject->id}/auth-logs");

        $this->assertKeys($payload, [
            'data', 'total', 'current_page', 'last_page', 'per_page', 'from', 'to',
            'counts', 'outcome',
        ], 'auth-logs: top-level');
        $this->assertKeys($payload['counts'], ['total', 'success', 'failed'], 'auth-logs: counts');

        $this->assertRowKeys($payload['data'], [
            'id', 'ip_address', 'user_agent', 'device_os', 'device_type',
            'is_successful', 'created_at',
        ], 'auth-logs: строка');
    }

    // ============================================
    // RBAC: moderator видит контент-табы, но НЕ finance/audit (admin only)
    // ============================================

    public function test_moderator_cannot_access_admin_only_tabs(): void
    {
        $moderator = User::factory()->moderator()->create();

        // Контент/активность-табы (moderator+admin) — 200:
        foreach (['photos', 'photo-comments', 'diaries', 'diary-comments', 'dating', 'chats', 'events'] as $tab) {
            $this->actingAs($moderator, 'web')
                ->getJson("/api/admin/users/{$this->subject->id}/{$tab}")
                ->assertOk("RBAC таба {$tab} не должен был измениться.");
        }

        // Финансы/аудит (admin only) — 403, RBAC не изменился:
        foreach (['finances', 'gifts', 'broadcasts', 'admin-logs', 'cards', 'promocodes', 'auth-logs'] as $tab) {
            $this->actingAs($moderator, 'web')
                ->getJson("/api/admin/users/{$this->subject->id}/{$tab}")
                ->assertStatus(403, "RBAC таба {$tab} не должен был измениться.");
        }
    }
}
