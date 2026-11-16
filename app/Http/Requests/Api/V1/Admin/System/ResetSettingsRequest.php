<?php

namespace App\Http\Requests\Api\V1\Admin\System;

use App\Http\Requests\Api\V1\Admin\AdminJsonRequest;

/**
 * Валидация сброса настроек группы к заводским
 * (POST /api/admin/settings/reset).
 *
 * Пакет 5 (рекомендация №6): правила дословно из SettingController::reset().
 */
class ResetSettingsRequest extends AdminJsonRequest
{
    public function rules(): array
    {
        return [
            'group' => 'required|string',
        ];
    }
}
