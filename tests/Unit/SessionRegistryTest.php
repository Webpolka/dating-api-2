<?php

namespace Tests\Unit;

use App\Services\V1\SessionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Юнит SessionRegistry (ПАКЕТ 6, рекомендация №7 аудита Шага 1).
 *
 * Покрывает database-стратегию (текущий прод: таблица sessions — источник
 * истины, поведение 1:1 со старым прямым SQL) и деградацию при
 * недоступном Redis (реестр не должен ронять бан/logout — ни при каких
 * условиях).
 *
 * Redis-стратегия (SESSION_DRIVER=redis) здесь намеренно не тестируется:
 * ей нужен живой Redis — она проверяется на staging по чек-листу из
 * .env.example (раздел «ПЕРЕЕЗД НА REDIS»).
 */
class SessionRegistryTest extends TestCase
{
    use RefreshDatabase;

    private SessionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        // Database-стратегия — источник истины таблица sessions
        // (в phpunit по умолчанию driver=array).
        config([
            'session.driver'        => 'database',
            'session.registry_enabled' => true,
        ]);

        $this->registry = app(SessionRegistry::class);
    }

    /** Вставить строку сессии. */
    private function insertSession(int $userId, string $id, int $lastActivity, string $ip = '127.0.0.1'): void
    {
        DB::table('sessions')->insert([
            'id'            => $id,
            'user_id'       => $userId,
            'ip_address'    => $ip,
            'user_agent'    => 'Mozilla/5.0 TestAgent',
            'payload'       => base64_encode(serialize([])),
            'last_activity' => $lastActivity,
        ]);
    }

    // ============================================
    // Чтение (database-стратегия)
    // ============================================

    public function test_all_for_returns_sessions_sorted_desc(): void
    {
        $this->insertSession(1, 'a', 100);
        $this->insertSession(1, 'b', 300);
        $this->insertSession(2, 'c', 200); // чужая — не должна попасть

        $rows = $this->registry->allFor(1);

        $this->assertSame(['b', 'a'], array_column($rows, 'id'), 'Сортировка по last_activity DESC, только сессии юзера.');

        $this->assertSame(
            ['id' => 'b', 'ip_address' => '127.0.0.1', 'user_agent' => 'Mozilla/5.0 TestAgent', 'last_activity' => 300],
            $rows[0],
        );
    }

    // ============================================
    // Проверка владения
    // ============================================

    public function test_peek_one_enforces_ownership(): void
    {
        $this->insertSession(1, 'owned', 100);
        $this->insertSession(2, 'foreign', 100);

        $this->assertSame('owned', $this->registry->peekOne(1, 'owned')['id']);
        $this->assertNull($this->registry->peekOne(1, 'foreign'), 'Сессия чужого юзера не должна находиться.');
        $this->assertNull($this->registry->peekOne(1, 'missing'), 'Несуществующая сессия — null.');
    }

    // ============================================
    // Уничтожение одной сессии
    // ============================================

    public function test_destroy_one_deletes_and_returns_meta(): void
    {
        $this->insertSession(1, 'doomed', 100, '10.0.0.7');

        $meta = $this->registry->destroyOne(1, 'doomed');

        $this->assertSame(
            [
                'id'            => 'doomed',
                'user_id'       => 1,
                'ip_address'    => '10.0.0.7',
                'user_agent'    => 'Mozilla/5.0 TestAgent',
                'last_activity' => 100,
            ],
            $meta,
        );
        $this->assertSame(0, DB::table('sessions')->where('id', 'doomed')->count());
    }

    public function test_destroy_one_of_another_user_is_noop(): void
    {
        $this->insertSession(2, 'foreign', 100);

        $this->assertNull($this->registry->destroyOne(1, 'foreign'));
        $this->assertSame(1, DB::table('sessions')->where('id', 'foreign')->count(), 'Чужая сессия обязана выжить.');
    }

    // ============================================
    // Уничтожение всех сессий (+ except)
    // ============================================

    public function test_destroy_all_for_counts_and_removes(): void
    {
        $this->insertSession(1, 'a', 100);
        $this->insertSession(1, 'b', 200);
        $this->insertSession(2, 'c', 300); // чужая — выживает

        $this->assertSame(2, $this->registry->destroyAllFor(1));
        $this->assertSame(0, DB::table('sessions')->where('user_id', 1)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', 2)->count());
    }

    public function test_destroy_all_for_spares_excepted_session(): void
    {
        $this->insertSession(1, 'keep-me', 100);
        $this->insertSession(1, 'kill-me', 200);

        // Паттерн logout-everywhere: текущая сессия выживает.
        $this->assertSame(1, $this->registry->destroyAllFor(1, 'keep-me'));

        $this->assertSame(1, DB::table('sessions')->where('id', 'keep-me')->count());
        $this->assertSame(0, DB::table('sessions')->where('id', 'kill-me')->count());
    }

    // ============================================
    // Деградация без Redis (главный инвариант реестра)
    // ============================================

    public function test_degradation_without_redis_never_throws(): void
    {
        // Не-database драйвер → редис-стратегия. В CI без Redis это
        // гарантированно ломается внутри — и обязано молча деградировать.
        config(['session.driver' => 'array']);

        $registry = app(SessionRegistry::class);

        // Запись не падает (no-op при недоступном Redis):
        $registry->touch(1, 'some-session', '127.0.0.1', 'test-agent');
        $registry->forget(1, 'some-session');

        // Чтение деградирует в пустой результат, а не в исключение:
        // (герметичность: юзер 42 никем не трогался — тест одинаков
        // на машинах с живым Redis и без него)
        $this->assertSame([], $registry->allFor(42));

        // Уничтожение возвращает «ноль работы», а не 500:
        $this->assertNull($registry->peekOne(42, 'some-session'));
        $this->assertNull($registry->destroyOne(42, 'some-session'));
        $this->assertSame(0, $registry->destroyAllFor(42));
    }

    public function test_touch_is_noop_when_registry_disabled(): void
    {
        config(['session.driver' => 'array', 'session.registry_enabled' => false]);

        $registry = app(SessionRegistry::class);

        // Выключатель: ни одна redis-операция не должна даже попытаться
        // выполниться (герметичность тестов / аварийный рубильник).
        $registry->touch(1, 's', '127.0.0.1', 'ua');
        $registry->forget(1, 's');

        $this->assertSame([], $registry->allFor(1));

        $this->addToAssertionCount(1); // доехали без исключений — тест пройден
    }
}
