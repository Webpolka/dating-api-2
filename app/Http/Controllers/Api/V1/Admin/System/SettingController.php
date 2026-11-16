<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Actions\V1\Admin\System\ManageSettingsAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\System\ResetSettingsRequest;
use App\Http\Requests\Api\V1\Admin\System\SaveSettingsRequest;
use App\Http\Resources\Api\V1\Admin\System\SettingResource;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Настройки системы (Setting).
 *
 * Three operations:
 *  - groups() — список групп настроек (general, limits, moderation, security,
 *    finance, seo, social) с человекочитаемыми labels.
 *  - index(?group=...) — список настроек конкретной группы (value type-cast).
 *  - save(group, settings[]) — массовое сохранение (через ManageSettingsAction:
 *    транзакция, JSON-валидация, coercion, аудит).
 *  - reset(group) — сброс к заводским значениям (через Action: транзакция + аудит).
 *
 * Save/reset возвращают 422 на \InvalidArgumentException (невалидный JSON в value)
 * и 500 на прочие исключения.
 *
 * ПАКЕТ 5 (рекомендация №6): валидация — в SaveSettingsRequest/
 * ResetSettingsRequest (формат 422 {"errors": {...}} сохранён через
 * AdminJsonRequest). В 500-ответах закрыта утечка getMessage() —
 * детали исключений в лог, наружу фиксированный текст.
 */
class SettingController extends AdminController
{
    /**
     * GET /api/admin/settings/groups — список групп с labels.
     */
    public function groups(): JsonResponse
    {
        $groups = Setting::select('group')->distinct()->orderBy('group')->pluck('group');

        $labels = [
            'general'    => 'Основные',
            'limits'     => 'Лимиты',
            'moderation' => 'Модерация',
            'security'   => 'Безопасность',
            'finance'    => 'Финансы',
            'seo'        => 'SEO',
            'social'     => 'Соц. сети',
        ];

        $formatted = $groups->map(fn($group) => [
            'key'   => $group,
            'label' => $labels[$group] ?? ucfirst($group),
        ]);

        return response()->json($formatted);
    }

    /**
     * GET /api/admin/settings?group=general — список настроек группы.
     */
    public function index(Request $request): JsonResponse
    {
        $group = $request->input('group', 'general');

        $settings = Setting::where('group', $group)->orderBy('id')->get();

        return response()->json(SettingResource::collection($settings)->resolve($request));
    }

    /**
     * POST /api/admin/settings/save — массовое сохранение настроек группы.
     *
     * Транзакция (JSON-валидация, coercion, save) + аудит — в ManageSettingsAction.
     */
    public function save(SaveSettingsRequest $request, ManageSettingsAction $action): JsonResponse
    {
        $data = $request->validated();
        $group = $data['group'];
        $admin = $request->user();

        try {
            $action->save($group, $data['settings'], $admin);

            return $this->message('Настройки сохранены!');
        } catch (\InvalidArgumentException $e) {
            // Доменная ошибка Action (невалидный JSON в value) — текст
            // пользовательский, отдаём как есть.
            return $this->message($e->getMessage(), 422);
        } catch (\Exception $e) {
            // Пакет 5 (рекомендация №3): закрыта утечка getMessage() в 500.
            Log::error('Ошибка сохранения настроек', [
                'group' => $group,
                'admin_id' => $admin->id,
                'error' => $e->getMessage(),
            ]);

            return $this->message('Не удалось сохранить настройки. Подробности — в логах приложения.', 500);
        }
    }

    /**
     * POST /api/admin/settings/reset — сброс настроек группы к заводским.
     *
     * Транзакция + аудит — в ManageSettingsAction::reset().
     */
    public function reset(ResetSettingsRequest $request, ManageSettingsAction $action): JsonResponse
    {
        $group = $request->validated()['group'];
        $admin = $request->user();

        try {
            $action->reset($group, $admin);

            return $this->message('Настройки сброшены к заводским значениям!');
        } catch (\Exception $e) {
            // Пакет 5 (рекомендация №3): закрыта утечка getMessage() в 500.
            Log::error('Ошибка сброса настроек', [
                'group' => $group,
                'admin_id' => $admin->id,
                'error' => $e->getMessage(),
            ]);

            return $this->message('Не удалось сбросить настройки. Подробности — в логах приложения.', 500);
        }
    }
}
