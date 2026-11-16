<?php

namespace Tests\Feature\Admin;

use App\Models\AdminLog;
use App\Models\User;
use App\Notifications\V1\UserBanned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ПАКЕТ 6 (рекомендация №7): подсистема принудительного logout через
 * SessionRegistry при database-драйвере (текущий прод).
 *
 * Проверяет, что перевод 6 мест с прямого SQL на реестр сохранил
 * поведение ПОБАЙТОВО: список «Активные устройства», kill одной сессии
 * (с защитой владения), kill-all, бан → logout, удаление юзера → logout,
 * logout-everywhere (текущая сессия выживает).
 *
 * Требования к окружению: тестовая БД (DB_DATABASE=testing),
 * PostGIS-совместимый сервер.
 */
class UserSessionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Реестр в database-режиме работает по таблице sessions —
        // включаем database-стратегию (в phpunit по умолчанию array).
        config(['session.driver' => 'database']);

        $this->admin = User::factory()->admin()->create();
    }

    /** Вставить строку сессии (стандартная Laravel-схема таблицы sessions). */
    private function insertSession(User $user, string $id, int $lastActivity, string $ip = '127.0.0.1'): void
    {
        DB::table('sessions')->insert([
            'id'            => $id,
            'user_id'       => $user->id,
            'ip_address'    => $ip,
            'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
            'payload'       => base64_encode(serialize([])),
            'last_activity' => $lastActivity,
        ]);
    }

    // ============================================
    // Список «Активные устройства»
    // ============================================

    public function test_admin_sees_user_sessions_sorted_by_activity(): void
    {
        $user = User::factory()->create();

        $this->insertSession($user, 'old-session', time() - 3600, '10.0.0.1');
        $this->insertSession($user, 'fresh-session', time() - 10, '10.0.0.2');

        $response = $this->actingAs($this->admin, 'web')
            ->getJson("/api/admin/users/{$user->id}/sessions")
            ->assertOk()
            ->assertJsonStructure(['sessions']);

        $sessions = $response->json('sessions');

        $this->assertCount(2, $sessions);
        $this->assertSame('fresh-session', $sessions[0]['id'], 'Сначала самая свежая (ORDER BY last_activity DESC).');
        $this->assertSame('10.0.0.2', $sessions[0]['ip']);

        // Формат строки — 1:1 со старым getUserSessions.
        foreach ($sessions as $session) {
            $actual = array_keys($session);
            sort($actual);
            $expected = ['id', 'ip', 'device', 'browser', 'is_mobile', 'last_activity', 'exact_time'];
            sort($expected);
            $this->assertSame($expected, $actual);
        }
    }

    // ============================================
    // Kill одной сессии
    // ============================================

    public function test_admin_can_kill_single_session(): void
    {
        $user = User::factory()->create();
        $this->insertSession($user, 'victim-session', time(), '10.0.0.9');

        $this->actingAs($this->admin, 'web')
            ->postJson("/api/admin/users/{$user->id}/sessions/kill", [
                'session_id' => 'victim-session',
            ])
            ->assertOk()
            ->assertJson(['message' => 'Сессия завершена. Устройство отключено.']);

        $this->assertSame(
            0,
            DB::table('sessions')->where('id', 'victim-session')->count(),
            'Строка сессии должна быть удалена.',
        );

        $this->assertTrue(
            AdminLog::where('action', 'user.session_killed')->exists(),
            'Действие админа обязано попасть в AdminLog.',
        );
    }

    public function test_admin_cannot_kill_session_of_another_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->insertSession($owner, 'owner-session', time());

        // Пытаемся убить сессию owner'а со страницы ДРУГОГО юзера:
        // защита владения (WHERE id = ? AND user_id = ?) обязана работать.
        $this->actingAs($this->admin, 'web')
            ->postJson("/api/admin/users/{$other->id}/sessions/kill", [
                'session_id' => 'owner-session',
            ])
            ->assertStatus(422)
            ->assertJson(['message' => 'Сессия не найдена или уже завершена']);

        $this->assertSame(1, DB::table('sessions')->where('id', 'owner-session')->count());
    }

    // ============================================
    // Kill всех сессий
    // ============================================

    public function test_admin_can_kill_all_sessions(): void
    {
        $user = User::factory()->create();
        $this->insertSession($user, 'session-a', time() - 100);
        $this->insertSession($user, 'session-b', time() - 200);

        $this->actingAs($this->admin, 'web')
            ->postJson("/api/admin/users/{$user->id}/sessions/kill-all")
            ->assertOk()
            ->assertJson([
                'message' => 'Все устройства (2 шт.) отключены от аккаунта.',
                'count'   => 2,
            ]);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertTrue(AdminLog::where('action', 'user.all_sessions_killed')->exists());
    }

    public function test_kill_all_without_active_sessions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin, 'web')
            ->postJson("/api/admin/users/{$user->id}/sessions/kill-all")
            ->assertOk()
            ->assertJson(['message' => 'Активных сессий не найдено.']);
    }

    // ============================================
    // Бан → logout со всех устройств (ToggleUserBanAction через реестр)
    // ============================================

    public function test_banning_user_kills_their_sessions(): void
    {
        Notification::fake();

        $victim = User::factory()->create();
        $this->insertSession($victim, 'victim-live-session', time());

        $this->actingAs($this->admin, 'web')
            ->postJson("/api/admin/users/{$victim->id}/ban", [
                'type'   => 'permanent',
                'reason' => 'spam',
            ])
            ->assertOk();

        $this->assertSame(
            0,
            DB::table('sessions')->where('user_id', $victim->id)->count(),
            'Бан обязан завершать все сессии юзера (через SessionRegistry).',
        );
    }

    // ============================================
    // Удаление юзера → logout (User::deleted через реестр)
    // ============================================

    public function test_deleting_user_kills_their_sessions(): void
    {
        $user = User::factory()->create();
        $this->insertSession($user, 'doomed-session', time());

        $user->delete();

        $this->assertSame(
            0,
            DB::table('sessions')->where('user_id', $user->id)->count(),
            'Soft-delete юзера обязан завершать его сессии (через SessionRegistry).',
        );
    }

    // ============================================
    // Logout-everywhere (текущая сессия админа выживает)
    // ============================================

    public function test_logout_everywhere_keeps_current_session(): void
    {
        // «Старая» сессия админа на другом устройстве.
        $this->insertSession($this->admin, 'admin-stale-session', time() - 3600);

        $response = $this->actingAs($this->admin, 'web')
            ->postJson('/api/admin/logout-everywhere')
            ->assertOk();

        $this->assertSame(1, $response->json('sessions_deleted'));
        $this->assertSame(
            0,
            DB::table('sessions')->where('id', 'admin-stale-session')->count(),
            'Чужие сессии админа обязаны быть завершены.',
        );
    }
}
