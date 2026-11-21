<?php

namespace App\Http\Requests\Api\V1\Admin\Moderation;

use App\Enums\V1\VerificationRejectReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Отклонение заявки верификации модератором
 * (POST /api/admin/verifications/{id}/reject).
 *
 * Причина — значение из enum VerificationRejectReason.
 * Используем VerificationRejectReason::userValues() — он не пропустит
 * системные причины (хотя для верификаций их нет, симметрия с фото).
 */
class RejectVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role:admin,moderator.
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(VerificationRejectReason::userValues())],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Укажите причину отклонения.',
            'reason.in'       => 'Недопустимая причина отклонения.',
        ];
    }
}
