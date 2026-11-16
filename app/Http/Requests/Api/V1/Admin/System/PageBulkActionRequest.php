<?php

namespace App\Http\Requests\Api\V1\Admin\System;

use App\Http\Requests\Api\V1\Admin\AdminJsonRequest;

/**
 * Валидация массовых действий над страницами
 * (POST /api/admin/pages/bulk).
 *
 * Пакет 5 (рекомендация №6): правила дословно из PageController::bulkAction().
 */
class PageBulkActionRequest extends AdminJsonRequest
{
    public function rules(): array
    {
        return [
            'ids'       => 'required|array|max:100',
            'ids.*'     => 'integer|exists:pages,id',
            'action'    => 'required|in:delete,activate,draft',
        ];
    }
}
