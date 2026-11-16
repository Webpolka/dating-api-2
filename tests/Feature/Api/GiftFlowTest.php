<?php

namespace Tests\Feature\Api;

use App\Models\Gift;
use App\Models\User;
use App\Models\UserBalance;
use App\Models\UserGift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Критический флоу: отправка подарков (деньги юзера).
 *
 * Цепочка: POST /api/users/{user}/gift
 *   → GiftController::store (guard-проверки: self-gift → 400,
 *     отсутствие счёта → 403, findOrFail → 404)
 *   → SendGiftAction::execute (ПАКЕТ 5, S5-7: атомарное списание
 *     conditional update WHERE credits >= price + UserGift со снапшотом)
 *   → InsufficientCreditsException → 402 (доменное исключение, не \Exception)
 *
 * Защита от гонки: списание — не «прочитал-проверил-записал», а один
 * UPDATE с условием. Если строка не затронута — кредитов не хватило.
 *
 * Требования к окружению: тестовая БД (DB_DATABASE=testing).
 */
class GiftFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $sender;

    private User $receiver;

    private Gift $gift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = User::factory()->create();
        $this->receiver = User::factory()->create();

        $this->gift = Gift::create([
            'name' => 'Роза',
            'slug' => 'rose',
            'image_url' => 'https://cdn.example.test/gifts/rose.png',
            'price' => 100,
            'category' => 'main',
            'type' => 'main',
            'is_active' => true,
        ]);
    }

    private function fund(User $user, int $credits): void
    {
        // У каждого юзера баланс уже есть (User::created-обсервер создаёт
        // UserBalance с 0) — тут только пополняем, second create бился об
        // unique user_balances_user_id_unique.
        $user->balance()->update(['credits' => $credits]);
    }

    /** Гость не может дарить — 401. */
    public function test_guest_cannot_send_gift(): void
    {
        $this->postJson("/api/users/{$this->receiver->slug}/gift", [
            'gift_id' => $this->gift->id,
        ])->assertStatus(401);
    }

    /** Валидация: gift_id обязателен и должен существовать. */
    public function test_invalid_payload_is_rejected(): void
    {
        $this->fund($this->sender, 1000);

        $this->actingAs($this->sender, 'web')
            ->postJson("/api/users/{$this->receiver->slug}/gift", [
                'message' => 'без gift_id',
            ])->assertStatus(422);

        $this->actingAs($this->sender, 'web')
            ->postJson("/api/users/{$this->receiver->slug}/gift", [
                'gift_id' => 999999,
            ])->assertStatus(422, 'exists:gifts,id — несуществующий подарок отбивается валидацией.');

        $this->assertSame(0, UserGift::count());
    }

    /** Подарок самому себе — 400. */
    public function test_user_cannot_gift_themselves(): void
    {
        $this->fund($this->sender, 1000);

        $this->actingAs($this->sender, 'web')
            ->postJson("/api/users/{$this->sender->slug}/gift", [
                'gift_id' => $this->gift->id,
            ])->assertStatus(400)
            ->assertJsonPath('success', false);

        $this->assertSame(0, UserGift::count());
    }

    /** Нет счёта (UserBalance удалён — legacy-юзер без счёта) — 403, не 402/500. */
    public function test_sender_without_balance_gets_403(): void
    {
        // У свежего юзера баланс уже есть (User::created-обсервер создаёт
        // UserBalance с 0) — ветка 403 живёт для legacy-строк без счёта.
        // Эмулируем: удаляем строку баланса.
        UserBalance::where('user_id', $this->sender->id)->delete();
        $this->sender->refresh();

        $this->actingAs($this->sender, 'web')
            ->postJson("/api/users/{$this->receiver->slug}/gift", [
                'gift_id' => $this->gift->id,
            ])->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->assertSame(0, UserGift::count());
    }

    /**
     * ГЛАВНЫЙ СЦЕНАРИЙ: подарок отправлен — кредиты списаны ровно на
     * цену, UserGift создан со СНАПШОТОМ (имя/цена/картинка на момент
     * отправки — история не ломается при смене каталога).
     */
    public function test_successful_gift_debits_balance_and_creates_snapshot(): void
    {
        $this->fund($this->sender, 500);

        $response = $this->actingAs($this->sender, 'web')
            ->postJson("/api/users/{$this->receiver->slug}/gift", [
                'gift_id' => $this->gift->id,
                'message' => 'С юбилеем!',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Подарок успешно отправлен!');

        // Кредиты списаны ровно на цену подарка
        $this->assertSame(
            400,
            (int) UserBalance::where('user_id', $this->sender->id)->value('credits'),
            'Со счёта списана цена подарка (500 - 100).',
        );

        // UserGift со снапшотом полей каталога
        $userGift = UserGift::query()->first();
        $this->assertNotNull($userGift);
        $this->assertSame($this->sender->id, $userGift->sender_id);
        $this->assertSame($this->receiver->id, $userGift->receiver_id);
        $this->assertSame($this->gift->id, $userGift->gift_id);
        $this->assertSame('Роза', $userGift->snapshot_name);
        $this->assertSame(100, $userGift->snapshot_price);
        $this->assertSame('https://cdn.example.test/gifts/rose.png', $userGift->snapshot_image_url);
        $this->assertSame('С юбилеем!', $userGift->message);
        $this->assertFalse((bool) $userGift->is_read, 'Подарок начинает жизнь непрочитанным.');
    }

    /**
     * Недостаточно кредитов — доменное 402 (InsufficientCreditsException),
     * баланс не тронут, UserGift не создан. Conditional update гарантирует
     * отсутствие «ухода в минус» даже при гонке параллельных отправок.
     */
    public function test_insufficient_credits_returns_402_and_rolls_back(): void
    {
        $this->fund($this->sender, 99);

        $this->actingAs($this->sender, 'web')
            ->postJson("/api/users/{$this->receiver->slug}/gift", [
                'gift_id' => $this->gift->id,
            ])->assertStatus(402)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Недостаточно кредитов для совершения покупки.');

        $this->assertSame(0, UserGift::count(), 'Транзакция откатилась: подарка нет.');
        $this->assertSame(
            99,
            (int) UserBalance::where('user_id', $this->sender->id)->value('credits'),
            'Баланс не изменился.',
        );
    }

    /** Ровно столько кредитов, сколько стоит подарок — хватает (>=). */
    public function test_exact_balance_is_sufficient(): void
    {
        $this->fund($this->sender, 100);

        $this->actingAs($this->sender, 'web')
            ->postJson("/api/users/{$this->receiver->slug}/gift", [
                'gift_id' => $this->gift->id,
            ])->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            0,
            (int) UserBalance::where('user_id', $this->sender->id)->value('credits'),
        );
    }

    /** Сообщение длиннее 150 символов — 422. */
    public function test_overlong_message_is_rejected(): void
    {
        $this->fund($this->sender, 1000);

        $this->actingAs($this->sender, 'web')
            ->postJson("/api/users/{$this->receiver->slug}/gift", [
                'gift_id' => $this->gift->id,
                'message' => str_repeat('а', 151),
            ])->assertStatus(422);

        $this->assertSame(0, UserGift::count());
    }
}
