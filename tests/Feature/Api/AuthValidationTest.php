<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ПАКЕТ 5 (рекомендация №6 аудита Шага 1): валидация auth-флоу переведена с
 * инлайн Validator::make на FormRequest'ы. Тесты фиксируют, что ОБА
 * legacy-формата 422-ответов сохранены байт-в-байт:
 *
 *   1) {"errors": {...}} — БЕЗ поля message
 *      (login, forgot-password, reset-password, email/change, confirm-password);
 *
 *   2) {"message": "The given data was invalid.", "errors": {...}}
 *      (register, register/step1, register/step2 — бывший хелпер validateJson()).
 *
 * Плюс: dating_goals теперь валидируется enum'ом DatingGoal (хардкод списка
 * убран) — принятое множество значений не изменилось (friends/romantic/
 * family/casual/travel).
 */
class AuthValidationTest extends TestCase
{
    use RefreshDatabase;

    // ================================================================
    // Формат 1: {"errors": {...}} без message
    // ================================================================

    public function test_login_validation_keeps_errors_only_format(): void
    {
        $response = $this->postJson('/api/login', []);

        $response->assertStatus(422);

        $json = $response->json();
        $this->assertArrayNotHasKey('message', $json, 'Формат login-422: message быть НЕ должно (legacy)');
        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('email', $json['errors']);
        $this->assertArrayHasKey('password', $json['errors']);
        $this->assertArrayHasKey('captchaInput', $json['errors']);
    }

    public function test_forgot_password_validation_keeps_errors_only_format(): void
    {
        $response = $this->postJson('/api/forgot-password', []);

        $response->assertStatus(422);

        $json = $response->json();
        $this->assertArrayNotHasKey('message', $json);
        $this->assertArrayHasKey('errors', $json);
        $this->assertArrayHasKey('email', $json['errors']);
        $this->assertArrayHasKey('captchaInput', $json['errors']);
    }

    public function test_reset_password_validation_keeps_errors_only_format(): void
    {
        $response = $this->postJson('/api/reset-password', [
            'password' => 'short',
        ]);

        $response->assertStatus(422);

        $json = $response->json();
        $this->assertArrayNotHasKey('message', $json);
        $this->assertArrayHasKey('errors', $json);
        // token и email отсутствуют, password не проходит Password::defaults()
        $this->assertArrayHasKey('token', $json['errors']);
        $this->assertArrayHasKey('email', $json['errors']);
        $this->assertArrayHasKey('password', $json['errors']);
    }

    // ================================================================
    // Формат 2: {"message": "The given data was invalid.", "errors": {...}}
    // (бывший validateJson)
    // ================================================================

    public function test_register_step1_keeps_message_field_format(): void
    {
        $response = $this->postJson('/api/register/step1', [
            'birth_day' => 32, // вне between:1,31
            'birth_month' => 13,
            'birth_year' => 1850,
            'name' => '',
            'gender' => 'helicopter',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_register_step1_rejects_impossible_date(): void
    {
        // Межполевая проверка checkdate() осталась в контроллере (осознанно:
        // не выражается правилами) — 31 февраля не проходит
        $response = $this->postJson('/api/register/step1', [
            'birth_day' => 31,
            'birth_month' => 2,
            'birth_year' => 2000,
            'name' => 'Тест',
            'gender' => 'male',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('birth_day', $response->json('errors'));
    }

    public function test_register_step2_validates_dating_goals_against_enum(): void
    {
        // 'partying' нет в enum DatingGoal (friends/romantic/family/casual/travel)
        $response = $this->postJson('/api/register/step2', [
            'dating_goals' => ['partying'],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.');

        $this->assertArrayHasKey('dating_goals.0', $response->json('errors'));
    }

    public function test_register_step2_accepts_all_enum_values(): void
    {
        $response = $this->postJson('/api/register/step2', [
            'dating_goals' => ['friends', 'romantic', 'family', 'casual', 'travel'],
        ]);

        // Успех: капча генерируется, GD отдаёт base64-картинку
        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'captchaImage']);
    }

    public function test_register_validates_email_and_password(): void
    {
        $existing = User::factory()->create();

        $response = $this->postJson('/api/register', [
            'name' => 'Тест',
            'gender' => 'male',
            'birth_day' => 1,
            'birth_month' => 1,
            'birth_year' => 1995,
            'dating_goals' => ['friends'],
            'email' => $existing->email, // уже занят
            'password' => 'short',       // слабый
            'captchaInput' => 'ABC12',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'The given data was invalid.');

        $errors = $response->json('errors');
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('password', $errors);
    }

    public function test_register_rejects_unknown_dating_goal(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Тест',
            'gender' => 'male',
            'birth_day' => 1,
            'birth_month' => 1,
            'birth_year' => 1995,
            'dating_goals' => ['skydiving'],
            'email' => 'free-' . uniqid() . '@example.com',
            'password' => 'Sup3r-Secret-Pass!',
            'captchaInput' => 'ABC12',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('dating_goals.0', $response->json('errors'));
    }
}
