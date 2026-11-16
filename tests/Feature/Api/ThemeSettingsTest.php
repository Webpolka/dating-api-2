<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ПАКЕТ 5 (рекомендация №6 аудита Шага 1): сохранение темы интерфейса.
 *
 * POST /api/settings/theme — до Пакета 5 был инлайн-замыканием в routes/api.php
 * с $request->validate(). Пакет 5 вынес логику в SettingsController::updateTheme
 * + UpdateThemeRequest. Контракт НЕ менялся:
 *   - успех: 200 {"success": true}, тема записана в preferences;
 *   - невалидная тема: 422 в ДЕФОЛТНОМ формате ValidationException
 *     ({"message": "...", "errors": {...}}) — как раньше у validate();
 *   - гость: 401.
 */
class ThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_set_theme(): void
    {
        $response = $this->postJson('/api/settings/theme', ['theme' => 'dark']);

        $response->assertStatus(401);
    }

    public function test_user_sets_valid_theme(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/settings/theme', ['theme' => 'dark']);

        $response->assertStatus(200)
            ->assertExactJson(['success' => true]);

        $this->assertSame(
            'dark',
            $user->preferences()->first()->theme,
            'Тема должна быть записана в user_preferences.theme',
        );
    }

    public function test_light_theme_is_still_valid(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/settings/theme', ['theme' => 'light']);

        $response->assertStatus(200)
            ->assertExactJson(['success' => true]);

        $this->assertSame('light', $user->preferences()->first()->theme);
    }

    public function test_invalid_theme_returns_422_in_default_validation_format(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/settings/theme', ['theme' => 'neon-pink']);

        $response->assertStatus(422);

        // Формат — дефолтный ValidationException ({"message", "errors"}),
        // ровно как раньше у $request->validate() в замыкании.
        $response->assertJsonStructure(['message', 'errors' => ['theme']]);
    }

    public function test_missing_theme_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/settings/theme', []);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['theme']]);
    }
}
