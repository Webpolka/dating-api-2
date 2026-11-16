<?php

namespace Tests\Feature\Admin;

use App\Enums\V1\DiaryStatus;
use App\Models\AdminLog;
use App\Models\Diary;
use App\Models\User;
use App\Notifications\V1\DiaryModerated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Модерация дневников (админ-часть).
 *
 * Цепочка: POST /api/admin/diary/{id}/approve|reject
 *   → DiaryController (admin+moderator, lockForUpdate)
 *   → ModerateDiaryAction::approve/reject (идемпотентность,
 *     транзакция, AdminLog::record, уведомление автору)
 *
 * Закрывает регрессии разбора монолита UserListingsController и
 * выноса write-логики в Action: форматы ответов и побочные эффекты
 * (AdminLog, нотификации) обязаны сохраниться.
 *
 * Требования к окружению: тестовая БД (DB_DATABASE=testing),
 * PostGIS-совместимый сервер.
 */
class DiaryModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $moderator;

    private User $author;

    private Diary $diary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moderator = User::factory()->moderator()->create();
        $this->author = User::factory()->create();

        $this->diary = Diary::create([
            'user_id' => $this->author->id,
            'title' => 'Мой первый дневник',
            'body' => '<p>Текст записи</p>',
            'status' => Diary::STATUS_PENDING,
        ]);
    }

    // ============================================
    // Доступ (role-matrix)
    // ============================================

    /** Гость — 401. */
    public function test_guest_cannot_moderate_diaries(): void
    {
        $this->postJson("/api/admin/diary/{$this->diary->id}/approve")
            ->assertStatus(401);
    }

    /** Обычный юзер — 403 (диарийная группа: admin + moderator). */
    public function test_regular_user_cannot_moderate_diaries(): void
    {
        $this->actingAs($this->author, 'web')
            ->postJson("/api/admin/diary/{$this->diary->id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Доступ запрещён. Требуется роль: admin, moderator');
    }

    /** Саппорт — 403: модерация контента не для него. */
    public function test_support_cannot_moderate_diaries(): void
    {
        $support = User::factory()->support()->create();

        $this->actingAs($support, 'web')
            ->postJson("/api/admin/diary/{$this->diary->id}/approve")
            ->assertStatus(403);
    }

    // ============================================
    // Списки и счётчики
    // ============================================

    /** Pending-запись видна в очереди модерации (дефолтный фильтр). */
    public function test_pending_diary_appears_in_moderation_queue(): void
    {
        $response = $this->actingAs($this->moderator, 'web')
            ->getJson('/api/admin/diary')
            ->assertOk();

        $response->assertJsonPath('tab', 'diaries')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $this->diary->id)
            ->assertJsonPath('data.0.status', Diary::STATUS_PENDING);
    }

    /** Счётчики статусов работают. */
    public function test_counts_endpoint_returns_stats(): void
    {
        $this->actingAs($this->moderator, 'web')
            ->getJson('/api/admin/diary/counts')
            ->assertOk()
            ->assertJsonStructure(['stats']);
    }

    // ============================================
    // Approve
    // ============================================

    /**
     * ГЛАВНЫЙ СЦЕНАРИЙ: публикация — статус сменился, аудит записан,
     * автор получил уведомление.
     */
    public function test_approve_publishes_diary_and_notifies_author(): void
    {
        Notification::fake();

        $this->actingAs($this->moderator, 'web')
            ->postJson("/api/admin/diary/{$this->diary->id}/approve", [
                'is_comments_enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Запись опубликована! Настройки применены.');

        $this->diary->refresh();
        // status кастуется в DiaryStatus-enum — сравниваем с кейсом enum,
        // не со строковой константой (assertSame строг к типам).
        $this->assertSame(DiaryStatus::Published, $this->diary->status);
        $this->assertSame($this->moderator->id, $this->diary->moderated_by, 'Кто модерировал — фиксируется.');
        $this->assertNotNull($this->diary->published_at);
        $this->assertNotNull($this->diary->moderated_at);
        $this->assertFalse((bool) $this->diary->is_comments_enabled, 'Мета-настройка из запроса применена.');

        // Аудит
        $this->assertTrue(
            AdminLog::where('action', 'diary.approve')->exists(),
            'Публикация обязана попадать в AdminLog.',
        );

        // Автор уведомлён
        Notification::assertSentTo($this->author, DiaryModerated::class);
    }

    /** Повторная публикация уже опубликованного — идемпотентна, без второго аудита. */
    public function test_approve_is_idempotent(): void
    {
        Notification::fake();

        $this->actingAs($this->moderator, 'web')
            ->postJson("/api/admin/diary/{$this->diary->id}/approve");

        $this->actingAs($this->moderator, 'web')
            ->postJson("/api/admin/diary/{$this->diary->id}/approve")
            ->assertOk()
            ->assertJsonPath('message', 'Запись уже опубликована');

        $this->assertSame(
            1,
            AdminLog::where('action', 'diary.approve')->count(),
            'Идемпотентный повтор не пишет второй AdminLog.',
        );

        // Уведомление отправляется один раз — только при смене статуса
        Notification::assertSentTimes(DiaryModerated::class, 1);
    }

    // ============================================
    // Reject
    // ============================================

    /** Отклонение: статус + причина + аудит + уведомление с причиной. */
    public function test_reject_stores_reason_and_notifies_author(): void
    {
        Notification::fake();

        $this->actingAs($this->moderator, 'web')
            ->postJson("/api/admin/diary/{$this->diary->id}/reject", [
                'reason' => 'spam',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Запись отклонена! Настройки применены.');

        $this->diary->refresh();
        $this->assertSame(DiaryStatus::Rejected, $this->diary->status);
        $this->assertSame('spam', $this->diary->reject_reason, 'Причина отказа сохранена.');
        $this->assertSame($this->moderator->id, $this->diary->moderated_by);

        $this->assertTrue(AdminLog::where('action', 'diary.reject')->exists());
        Notification::assertSentTo($this->author, DiaryModerated::class);
    }

    /** Причина вне словаря DiaryRejectReason — 422, статус не меняется. */
    public function test_reject_with_invalid_reason_is_rejected(): void
    {
        $this->actingAs($this->moderator, 'web')
            ->postJson("/api/admin/diary/{$this->diary->id}/reject", [
                'reason' => 'мне-не-нравится',
            ])->assertStatus(422);

        $this->diary->refresh();
        $this->assertSame(DiaryStatus::Pending, $this->diary->status, 'Запись осталась в очереди.');
        $this->assertSame(0, AdminLog::where('action', 'diary.reject')->count());
    }

    /** Причина обязательна. */
    public function test_reject_without_reason_is_rejected(): void
    {
        $this->actingAs($this->moderator, 'web')
            ->postJson("/api/admin/diary/{$this->diary->id}/reject", [])
            ->assertStatus(422);
    }

    // ============================================
    // Несуществующая запись
    // ============================================

    /** Модерация несуществующего ID — 404 с чистым сообщением. */
    public function test_moderating_missing_diary_returns_404(): void
    {
        $this->actingAs($this->moderator, 'web')
            ->postJson('/api/admin/diary/999999/approve')
            ->assertStatus(404);
    }
}
