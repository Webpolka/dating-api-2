<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Web\UpdateThemeRequest;

/**
 * Настройки пользователя (Web API).
 *
 * Пакет 5 (рекомендация №6 аудита Шага 1, хвост): роут /settings/theme был
 * инлайн-замыканием в routes/api.php с $request->validate() — FormRequest
 * к замыканию не привязать без выноса в контроллер. Логика перенесена
 * дословно, форматы ответов не менялись.
 */
class SettingsController extends Controller
{
    /**
     * POST /api/settings/theme — сохранение темы интерфейса (light/dark).
     *
     * Валидация — UpdateThemeRequest (422 — дефолтный ValidationException,
     * ровно как раньше у $request->validate() в замыкании).
     */
    public function updateTheme(UpdateThemeRequest $request)
    {
        $request->user()->preferences()->update([
            'theme' => $request->validated()['theme'],
        ]);

        return response()->json(['success' => true]);
    }
}
