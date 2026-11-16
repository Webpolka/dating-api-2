<?php

namespace Tests\Feature\Api;

use App\Jobs\V1\Admin\Finance\ProcessSubscriptionPaymentGateway;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Критический флоу: оплата подписки.
 *
 * Цепочка: POST /api/subscriptions/pay
 *   → SubscriptionController::pay (валидация, защита от дублей tier,
 *     идемпотентность по Idempotency-Key / анти-даблклик)
 *   → Transaction (pending) + ProcessSubscriptionPaymentGateway (mock-acquiring)
 *
 * Примечание: дефолт return_url теперь config('app.frontend_url') —
 * баг с route('home') (RouteNotFoundException при запросе без return_url)
 * исправлен в Пакете 1 «Деньги». Тесты по-прежнему передают return_url явно.
 */
class SubscriptionPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SubscriptionPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->plan = SubscriptionPlan::factory()->create();
    }

    public function test_guest_cannot_pay(): void
    {
        $this->postJson('/api/subscriptions/pay', [
            'plan_id' => $this->plan->id,
            'payment_method' => 'card',
            'return_url' => 'https://frontend.test/success',
        ])->assertStatus(401);
    }

    public function test_user_can_start_subscription_payment(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user, 'web')
            ->postJson('/api/subscriptions/pay', [
                'plan_id' => $this->plan->id,
                'payment_method' => 'card',
                'return_url' => 'https://frontend.test/success',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'redirect_url']);

        // Создана pending-транзакция с корректными полями
        $transaction = Transaction::query()
            ->where('user_id', $this->user->id)
            ->where('type', Transaction::TYPE_SUBSCRIPTION)
            ->first();

        $this->assertNotNull($transaction);
        $this->assertSame(Transaction::STATUS_PENDING, $transaction->status);
        $this->assertEquals($this->plan->price, $transaction->amount);
        $this->assertSame($this->plan->id, $transaction->meta['plan_id']);

        // Симуляция банка уехала в очередь payments
        Queue::assertPushed(ProcessSubscriptionPaymentGateway::class);
    }

    public function test_user_cannot_buy_same_tier_twice(): void
    {
        UserSubscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'tier' => $this->plan->tier,
            'starts_at' => now()->subDays(1),
            'ends_at' => now()->addDays(29),
            'is_auto_renew' => true,
            'status' => UserSubscription::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($this->user, 'web')
            ->postJson('/api/subscriptions/pay', [
                'plan_id' => $this->plan->id,
                'payment_method' => 'card',
                'return_url' => 'https://frontend.test/success',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false);

        // Новой транзакции не создано
        $this->assertSame(
            0,
            Transaction::where('user_id', $this->user->id)->count()
        );
    }

    public function test_expired_subscription_allows_repurchase(): void
    {
        Queue::fake();

        UserSubscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'tier' => $this->plan->tier,
            'starts_at' => now()->subDays(31),
            'ends_at' => now()->subDays(1), // истекла
            'is_auto_renew' => false,
            'status' => UserSubscription::STATUS_EXPIRED,
        ]);

        $this->actingAs($this->user, 'web')
            ->postJson('/api/subscriptions/pay', [
                'plan_id' => $this->plan->id,
                'payment_method' => 'yoomoney',
                'return_url' => 'https://frontend.test/success',
            ])->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_invalid_payment_method_is_rejected(): void
    {
        $this->actingAs($this->user, 'web')
            ->postJson('/api/subscriptions/pay', [
                'plan_id' => $this->plan->id,
                'payment_method' => 'paypal',
                'return_url' => 'https://frontend.test/success',
            ])->assertStatus(422);
    }

    public function test_nonexistent_plan_is_rejected(): void
    {
        $this->actingAs($this->user, 'web')
            ->postJson('/api/subscriptions/pay', [
                'plan_id' => 999999,
                'payment_method' => 'card',
                'return_url' => 'https://frontend.test/success',
            ])->assertStatus(422);
    }

    /**
     * Пакет 1 «Деньги» (В5): повторный запрос с тем же Idempotency-Key
     * возвращает СУЩЕСТВУЮЩУЮ транзакцию, не создавая новую.
     */
    public function test_idempotency_key_returns_same_transaction(): void
    {
        Queue::fake();

        $payload = [
            'plan_id' => $this->plan->id,
            'payment_method' => 'card',
            'return_url' => 'https://frontend.test/success',
        ];

        $this->actingAs($this->user, 'web')
            ->postJson('/api/subscriptions/pay', $payload, ['Idempotency-Key' => 'test-key-123'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('idempotent_replay', false);

        $this->actingAs($this->user, 'web')
            ->postJson('/api/subscriptions/pay', $payload, ['Idempotency-Key' => 'test-key-123'])
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);

        // Создана ровно ОДНА транзакция (дубль не создан)
        $this->assertSame(
            1,
            Transaction::where('user_id', $this->user->id)->count()
        );
    }

    /**
     * Пакет 1 «Деньги» (В5): анти-даблклик без ключа — живая pending-
     * транзакция того же плана моложе 60 сек возвращается вместо новой.
     */
    public function test_double_click_returns_recent_pending_transaction(): void
    {
        Queue::fake();

        $payload = [
            'plan_id' => $this->plan->id,
            'payment_method' => 'card',
            'return_url' => 'https://frontend.test/success',
        ];

        $this->actingAs($this->user, 'web')
            ->postJson('/api/subscriptions/pay', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', false);

        // «Двойной клик» без Idempotency-Key
        $this->actingAs($this->user, 'web')
            ->postJson('/api/subscriptions/pay', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);

        $this->assertSame(
            1,
            Transaction::where('user_id', $this->user->id)->count()
        );
    }
}
