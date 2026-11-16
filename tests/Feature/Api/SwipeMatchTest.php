<?php

namespace Tests\Feature\Api;

use App\Models\Chat;
use App\Models\Swipe;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Критический флоу: свайпы и мэтчи.
 *
 * Цепочка: POST /api/users/{user}/swipe
 *   → SwipeController::store (валидация типа, запрет self-swipe)
 *   → upsertSwipe (updateOrCreate, уникальность user_id+target_user_id,
 *     ре-свайп после rewind сбрасывает rewinded_at)
 *   → при взаимном like/superlike → UserMatch::createMatch
 *     (пара user1_id < user2_id, реактивация unmatched)
 *   → UserMatchObserver: чат между парой + инвалидация кэшей лент.
 *
 * ПАКЕТ 2 «Чаты и матчи»: закрывает регрессию «is_match=true, но мэтч
 * в БД не создавался» (FIX 1 в SwipeController) и гонку двойного клика
 * (транзакция вокруг upsert + проверки взаимности).
 *
 * Требования к окружению: тестовая БД (DB_DATABASE=testing),
 * PostGIS-совместимый сервер.
 */
class SwipeMatchTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
    }

    /**
     * Смена актёра внутри одного теста. Роут — auth:sanctum, а sanctum-гард
     * КЭШИРУЕТ юзера между запросами в рамках теста: actingAs() на втором
     * запросе не перебивает первого (встречный свайп Alice→Bob падал 403
     * «сам себе» — sanctum всё ещё видел Боба). forgetGuards() сбрасывает кэш.
     */
    private function loginAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    /** Свайп от гостя — 401 (роут под auth:sanctum). */
    public function test_guest_cannot_swipe(): void
    {
        $this->postJson("/api/users/{$this->bob->slug}/swipe", [
            'type' => 'like',
        ])->assertStatus(401);
    }

    /** Направление свайпа валидируется (только like|dislike). */
    public function test_invalid_swipe_type_is_rejected(): void
    {
        $this->loginAs($this->alice)
            ->postJson("/api/users/{$this->bob->slug}/swipe", [
                'type' => 'superlike',
            ])->assertStatus(422);

        $this->loginAs($this->alice)
            ->postJson("/api/users/{$this->bob->slug}/swipe", [
                'type' => 'whatever',
            ])->assertStatus(422);

        $this->assertSame(0, Swipe::count());
    }

    /** Свайп самому себе — 403. */
    public function test_user_cannot_swipe_themselves(): void
    {
        $this->loginAs($this->alice)
            ->postJson("/api/users/{$this->alice->slug}/swipe", [
                'type' => 'like',
            ])->assertStatus(403);

        $this->assertSame(0, Swipe::count());
    }

    /** Обычный like без встречного — мэтча нет, свайп записан. */
    public function test_like_without_reverse_swipe_creates_no_match(): void
    {
        $response = $this->loginAs($this->alice)
            ->postJson("/api/users/{$this->bob->slug}/swipe", [
                'type' => 'like',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_match', false)
            ->assertJsonPath('current_swipe', 'like');

        $this->assertSame(1, Swipe::count());
        $this->assertSame(0, UserMatch::count(), 'Без встречного свайпа мэтч не создаётся.');
    }

    /**
     * ГЛАВНЫЙ СЦЕНАРИЙ: взаимный like → мэтч создан в БД
     * (регрессия FIX 1: раньше API возвращал is_match=true, но
     * UserMatch не создавался), чат между парой создан обсервером.
     */
    public function test_mutual_like_creates_match_and_chat(): void
    {
        // 1. Bob лайкает Alice
        $this->loginAs($this->bob)
            ->postJson("/api/users/{$this->alice->slug}/swipe", [
                'type' => 'like',
            ])->assertJsonPath('is_match', false);

        // 2. Alice лайкает Bob в ответ — мэтч!
        $this->loginAs($this->alice)
            ->postJson("/api/users/{$this->bob->slug}/swipe", [
                'type' => 'like',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_match', true);

        // Мэтч в БД, пара приведена к правилу user1_id < user2_id
        $match = UserMatch::query()->first();
        $this->assertNotNull($match, 'UserMatch обязан быть создан при взаимном лайке (FIX 1).');
        $this->assertSame(UserMatch::STATUS_ACTIVE, $match->status);
        $this->assertSame(min($this->alice->id, $this->bob->id), $match->user1_id);
        $this->assertSame(max($this->alice->id, $this->bob->id), $match->user2_id);

        // Ровно один мэтч (не дублируется при повторных свайпах)
        $this->assertSame(1, UserMatch::count());

        // Обсервер создал приватный чат между парой
        $chat = Chat::query()->first();
        $this->assertNotNull($chat, 'UserMatchObserver обязан создать чат при мэтче.');
        $this->assertSame(Chat::TYPE_PRIVATE, $chat->type);
        $this->assertSame(
            2,
            $chat->participants()->count(),
            'В чате мэтча должно быть ровно два участника.',
        );
    }

    /** Встречный dislike не создаёт мэтч (проверяется только положительный свайп). */
    public function test_like_against_dislike_creates_no_match(): void
    {
        $this->loginAs($this->bob)
            ->postJson("/api/users/{$this->alice->slug}/swipe", [
                'type' => 'dislike',
            ]);

        $this->loginAs($this->alice)
            ->postJson("/api/users/{$this->bob->slug}/swipe", [
                'type' => 'like',
            ])->assertJsonPath('is_match', false);

        $this->assertSame(0, UserMatch::count());
    }

    /**
     * Rewound-свайп (rewinded_at заполнен — юзер отмотал лайк)
     * не считается встречным: мэтч не создаётся.
     */
    public function test_rewinded_reverse_swipe_does_not_create_match(): void
    {
        Swipe::create([
            'user_id' => $this->bob->id,
            'target_user_id' => $this->alice->id,
            'type' => Swipe::TYPE_LIKE,
            'rewinded_at' => now(),
        ]);

        $this->loginAs($this->alice)
            ->postJson("/api/users/{$this->bob->slug}/swipe", [
                'type' => 'like',
            ])->assertJsonPath('is_match', false);

        $this->assertSame(0, UserMatch::count());
    }

    /**
     * Повторный свайп того же юзера — UPDATE существующей записи
     * (unique user_id+target_user_id), а не INSERT дубля.
     * Сценарий: dislike → передумал → like.
     */
    public function test_repeated_swipe_updates_same_row(): void
    {
        $this->loginAs($this->alice)
            ->postJson("/api/users/{$this->bob->slug}/swipe", [
                'type' => 'dislike',
            ]);

        $this->loginAs($this->alice)
            ->postJson("/api/users/{$this->bob->slug}/swipe", [
                'type' => 'like',
            ]);

        $this->assertSame(
            1,
            Swipe::where('user_id', $this->alice->id)
                ->where('target_user_id', $this->bob->id)
                ->count(),
            'Повторный свайп обновляет запись, дубль не создаётся.',
        );

        $swipe = Swipe::where('user_id', $this->alice->id)->first();
        $this->assertSame(Swipe::TYPE_LIKE, $swipe->type, 'Тип свайпа обновлён на like.');
        $this->assertNull($swipe->rewinded_at, 'rewinded_at сброшен при ре-свайпе.');
    }

    /**
     * Re-match: если пара уже была unmatch'нута, новый взаимный лайк
     * реактивирует СТАРЫЙ мэтч (unique-конфликт в createMatch), а не
     * создаёт второй.
     */
    public function test_rematch_reactivates_unmatched_match(): void
    {
        $match = UserMatch::create([
            'user1_id' => min($this->alice->id, $this->bob->id),
            'user2_id' => max($this->alice->id, $this->bob->id),
            'status' => UserMatch::STATUS_UNMATCHED,
            'unmatched_by' => $this->alice->id,
            'unmatched_at' => now()->subDay(),
        ]);

        $this->loginAs($this->bob)
            ->postJson("/api/users/{$this->alice->slug}/swipe", ['type' => 'like']);

        $this->loginAs($this->alice)
            ->postJson("/api/users/{$this->bob->slug}/swipe", ['type' => 'like'])
            ->assertJsonPath('is_match', true);

        $this->assertSame(1, UserMatch::count(), 'Второй мэтч на ту же пару не создаётся.');

        $match->refresh();
        $this->assertSame(UserMatch::STATUS_ACTIVE, $match->status, 'Старый мэтч реактивирован.');
        $this->assertNull($match->unmatched_by);
        $this->assertNull($match->unmatched_at);
    }
}
