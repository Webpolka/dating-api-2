<?php

namespace App\Http\Requests\Api\V1\Admin\System;

use App\Http\Requests\Api\V1\Admin\AdminJsonRequest;

/**
 * Валидация создания статической страницы (POST /api/admin/pages).
 *
 * Пакет 5 (рекомендация №6): правила дословно из PageController::store().
 * Формат 422 — {"errors": {...}} (legacy этого контроллера).
 */
class StorePageRequest extends AdminJsonRequest
{
    public function rules(): array
    {
        return [
            'title'            => 'required|string|max:255',
            'meta_title'       => 'nullable|string|max:255',
            'slug'             => 'required|regex:/^[a-z0-9-]+$/|unique:pages,slug',
            'body'             => 'nullable|string',
            'meta_description' => 'nullable|string|max:500',
            'is_active'        => 'boolean',
        ];
    }
}
