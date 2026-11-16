<?php

namespace App\Http\Requests\Api\V1\Admin\System;

use App\Http\Requests\Api\V1\Admin\AdminJsonRequest;

/**
 * Валидация обновления статической страницы (PUT /api/admin/pages/{id}).
 *
 * Пакет 5 (рекомендация №6): правила дословно из PageController::update(),
 * включая string-форму unique с ignore по id роута (как в оригинале).
 */
class UpdatePageRequest extends AdminJsonRequest
{
    public function rules(): array
    {
        return [
            'title'            => 'required|string|max:255',
            'meta_title'       => 'nullable|string|max:255',
            'slug'             => 'required|regex:/^[a-z0-9-]+$/|unique:pages,slug,' . $this->route('id'),
            'body'             => 'nullable|string',
            'meta_description' => 'nullable|string|max:500',
            'is_active'        => 'boolean',
        ];
    }
}
