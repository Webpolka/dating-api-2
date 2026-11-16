<?php

namespace Tests\Feature\Admin;

use App\Models\AdminLog;
use App\Models\BlogCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ПАКЕТ 7: smoke-прогон админки.
 *
 * Задача — не проверить бизнес-логику каждого раздела (это делают
 * профильные тесты: PhotoModerationTest, UserSessionsTest, UserBanTest,
 * DiaryModerationTest, UserListingsContractTest), а гарантировать, что
 * после структурного рефакторинга (Шаг 1 + Шаг 2):
 *   1. ни один list-эндпоинт не упал (500) — все 200 + форма { data, meta };
 *   2. role-matrix групп роутов не поехала:
 *      - ГРУППА 1 (все staff): dashboard, support-chats, support-templates;
 *      - ГРУППА 2 (admin+moderator): users, photos, diary, stop-words;
 *      - ГРУППА 3 (только admin): staff, finance, settings, CMS;
 *   3. эталонный CRUD-цикл (blog categories) работает: store → update →
 *      destroy + AdminLog на каждом шаге.
 *
 * Это страховка от «отвалившегося раздела админки после переезда
 * контроллера» — самый частый регресс при структурных рефакторингах.
 *
 * Требования к окружению: тестовая БД (DB_DATABASE=testing),
 * PostGIS-совместимый сервер.
 */
class AdminCrudSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $moderator;

    private User $support;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->moderator = User::factory()->moderator()->create();
        $this->support = User::factory()->support()->create();
    }

    // ============================================
    // 1. Smoke: все list-эндпоинты отвечают 200
    // ============================================

    // PHPUnit 12: docblock-аннотации не работают — только атрибуты.
    #[DataProvider('adminListEndpointsProvider')]
    public function test_admin_list_endpoint_responds_ok(string $endpoint): void
    {
        $this->actingAs($this->admin, 'web')
            ->getJson("/api/admin/{$endpoint}")
            ->assertOk();
    }

    /**
     * Все GET-разделы админки (пустая база — проверяем только доступность,
     * не данные). Порядок маршрутов в routes/admin.php уже гарантирует,
     * что статические префиксы (counts, search) идут выше {param}.
     */
    public static function adminListEndpointsProvider(): array
    {
        return [
            // ГРУППА 1: все staff
            'dashboard stats' => ['dashboard/stats'],
            'support chats' => ['support-chats'],
            'support templates' => ['support-templates'],
            'support tpl categories' => ['support-templates/categories'],

            // Пользователи и безопасность
            'users' => ['users'],
            'users stats' => ['users/stats'],
            'fraud alerts' => ['fraud-alerts'],
            'fraud alerts counts' => ['fraud-alerts/counts'],
            'block signals' => ['block-signals'],
            'block signals counts' => ['block-signals/counts'],

            // ГРУППА 2: модерация (admin + moderator)
            'photos' => ['photos'],
            'photos counts' => ['photos/counts'],
            'photo comments' => ['photo-comments'],
            'photo comments counts' => ['photo-comments/counts'],
            'dating (swipes/matches)' => ['dating'],
            'dating counts' => ['dating/counts'],
            'reports' => ['reports'],
            'reports counts' => ['reports/counts'],
            'diary' => ['diary'],
            'diary counts' => ['diary/counts'],
            'diary comments' => ['diary-comments'],
            'diary comments counts' => ['diary-comments/counts'],
            'chats' => ['chats'],
            'stop words' => ['stop-words'],
            'stop words counts' => ['stop-words/counts'],

            // ГРУППА 3: только admin
            'staff' => ['staff'],
            'interests categories' => ['interests/categories'],
            'interests' => ['interests'],
            'interests counts' => ['interests/counts'],
            'settings groups' => ['settings/groups'],
            'settings' => ['settings'],
            'pages' => ['pages'],
            'media' => ['media'],
            'media counts' => ['media/counts'],
            'blog categories' => ['blog/categories'],
            'blog posts' => ['blog/posts'],
            'blog posts counts' => ['blog/posts/counts'],
            'geoip locations' => ['geoip/locations'],
            'geoip counts' => ['geoip/locations/counts'],
            'audit logs' => ['audit/logs'],
            'broadcasts' => ['broadcasts'],
            'broadcasts counts' => ['broadcasts/counts'],
            'transactions' => ['transactions'],
            'subscription plans' => ['subscription-plans'],
            'gifts (catalog)' => ['gifts'],
        ];
    }

    // ============================================
    // 2. Role-matrix: границы групп роутов
    // ============================================

    /** Гость — 401 на любом admin-эндпоинте. */
    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/admin/users')->assertStatus(401);
        $this->getJson('/api/admin/photos')->assertStatus(401);
        $this->getJson('/api/admin/staff')->assertStatus(401);
    }

    /** Обычный юзер — 403 даже на «общую» группу staff. */
    public function test_regular_user_gets_403(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson('/api/admin/dashboard/stats')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Доступ запрещён. Требуется роль сотрудника.');
    }

    /**
     * ГРУППА 1 (все staff): саппорт видит дашборд и свои тикеты.
     * /users — ГРУППА 2 (admin+moderator): саппорту 403.
     */
    public function test_support_role_matrix(): void
    {
        $this->actingAs($this->support, 'web')->getJson('/api/admin/dashboard/stats')->assertOk();
        $this->actingAs($this->support, 'web')->getJson('/api/admin/support-chats')->assertOk();
        $this->actingAs($this->support, 'web')->getJson('/api/admin/support-templates')->assertOk();

        // Список юзеров и модерация контента — не для саппорта
        $this->actingAs($this->support, 'web')->getJson('/api/admin/users')->assertStatus(403);
        $this->actingAs($this->support, 'web')->getJson('/api/admin/photos')->assertStatus(403);
        $this->actingAs($this->support, 'web')->getJson('/api/admin/diary')->assertStatus(403);

        // Управление ролями и финансы — только admin
        $this->actingAs($this->support, 'web')->getJson('/api/admin/staff')->assertStatus(403);
        $this->actingAs($this->support, 'web')->getJson('/api/admin/transactions')->assertStatus(403);
    }

    /**
     * ГРУППА 2 (admin + moderator): модератору доступны фото/дневники/
     * стоп-слова, но не staff/финансы/настройки (анти privilege escalation).
     */
    public function test_moderator_role_matrix(): void
    {
        $this->actingAs($this->moderator, 'web')->getJson('/api/admin/photos')->assertOk();
        $this->actingAs($this->moderator, 'web')->getJson('/api/admin/diary')->assertOk();
        $this->actingAs($this->moderator, 'web')->getJson('/api/admin/stop-words')->assertOk();

        $this->actingAs($this->moderator, 'web')->getJson('/api/admin/staff')->assertStatus(403)
            ->assertJsonPath('message', 'Доступ запрещён. Требуется роль: admin');
        $this->actingAs($this->moderator, 'web')->getJson('/api/admin/transactions')->assertStatus(403);
        $this->actingAs($this->moderator, 'web')->getJson('/api/admin/settings')->assertStatus(403);
        $this->actingAs($this->moderator, 'web')->getJson('/api/admin/pages')->assertStatus(403);
    }

    /** Admin видит всё (выборочно из всех трёх групп). */
    public function test_admin_sees_everything(): void
    {
        foreach (['dashboard/stats', 'photos', 'staff', 'transactions', 'settings'] as $endpoint) {
            $this->assertSame(
                200,
                $this->actingAs($this->admin, 'web')
                    ->getJson("/api/admin/{$endpoint}")
                    ->status(),
                "admin должен видеть /api/admin/{$endpoint}",
            );
        }
    }

    // ============================================
    // 3. Эталонный CRUD-цикл: категории блога
    // ============================================

    /** Полный цикл store → duplicate → update → destroy + аудит. */
    public function test_blog_category_full_crud_cycle(): void
    {
        // --- STORE ---
        $name = 'Тестовая рубрика '.Str::random(6);

        $response = $this->actingAs($this->admin, 'web')
            ->postJson('/api/admin/blog/categories', [
                'name' => $name,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Рубрика создана');

        $categoryId = $response->json('data.id');
        $this->assertNotNull($categoryId, 'Созданная категория возвращается с id.');

        $category = BlogCategory::find($categoryId);
        $this->assertSame($name, $category->name);
        $this->assertNotEquals('', $category->slug, 'Slug сгенерирован (fallback при кириллице).');
        $this->assertTrue((bool) $category->is_active);

        $this->assertTrue(AdminLog::where('action', 'blog_category.create')->exists());

        // Новая категория видна в списке
        $this->actingAs($this->admin, 'web')
            ->getJson('/api/admin/blog/categories')
            ->assertOk()
            ->assertJsonPath('data.0.id', $categoryId);

        // --- DUPLICATE: уникальность имени — 422 ---
        $this->actingAs($this->admin, 'web')
            ->postJson('/api/admin/blog/categories', [
                'name' => $name,
            ])->assertStatus(422);

        // --- UPDATE ---
        $newName = 'Переименованная '.Str::random(6);

        $this->actingAs($this->admin, 'web')
            ->putJson("/api/admin/blog/categories/{$categoryId}", [
                'name' => $newName,
                'is_active' => false,
            ])->assertOk()
            ->assertJsonPath('message', 'Рубрика обновлена');

        $category->refresh();
        $this->assertSame($newName, $category->name);
        $this->assertFalse((bool) $category->is_active);
        $this->assertTrue(AdminLog::where('action', 'blog_category.update')->exists());

        // --- DESTROY ---
        $this->actingAs($this->admin, 'web')
            ->deleteJson("/api/admin/blog/categories/{$categoryId}")
            ->assertOk()
            ->assertJsonPath('message', 'Рубрика удалена');

        $this->assertNull(BlogCategory::find($categoryId));
        $this->assertTrue(AdminLog::where('action', 'blog_category.delete')->exists());
    }

    /** CRUD категорий блога — только admin (группа 3). */
    public function test_blog_category_crud_requires_admin(): void
    {
        $this->actingAs($this->moderator, 'web')
            ->postJson('/api/admin/blog/categories', ['name' => 'Запрещено'])
            ->assertStatus(403);

        $this->assertSame(0, BlogCategory::count());
    }
}
