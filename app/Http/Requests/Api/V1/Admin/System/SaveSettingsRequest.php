<?php

namespace App\Http\Requests\Api\V1\Admin\System;

use App\Http\Requests\Api\V1\Admin\AdminJsonRequest;

/**
 * Валидация массового сохранения настроек группы
 * (POST /api/admin/settings/save).
 *
 * Пакет 5 (рекомендация №6): правила дословно из SettingController::save().
 * Формат 422 — {"errors": {...}} (legacy этого контроллера).
 */
class SaveSettingsRequest extends AdminJsonRequest
{
    public function rules(): array
    {
        return [
            'group'              => 'required|string',
            'settings'           => 'required|array',
            'settings.*.id'      => 'required|exists:settings,id',
            'settings.*.value'   => 'nullable',
        ];
    }
}
