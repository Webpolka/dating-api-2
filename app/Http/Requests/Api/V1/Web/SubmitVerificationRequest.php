<?php

namespace App\Http\Requests\Api\V1\Web;

use App\Models\Photo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Подача заявки на верификацию (POST /api/verification/submit).
 *
 * photo_id — должен быть:
 *  - существующей записью Photo;
 *  - принадлежать текущему юзеру;
 *  - type = Photo::TYPE_VERIFICATION ('verification');
 *  - status = Photo::STATUS_PENDING ('pending') — фото не должно быть
 *    одобрено/отклонено ранее (verification-фото после модерации верификации
 *    остаётся в pending, модератор решает через Verification flow, а не Photo).
 *
 * Код верификации НЕ передаётся в body — он берётся из Redis
 * (verify:code:{user_id}), который был выдан POST /api/verification/code.
 * Если код истёк или не запрашивался — 422.
 */
class SubmitVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'photo_id' => [
                'required',
                'integer',
                Rule::exists('photos', 'id')->where(function ($q) {
                    $q->where('user_id', $this->user()->id)
                        ->where('type', Photo::TYPE_VERIFICATION)
                        ->where('status', Photo::STATUS_PENDING)
                        ->whereNull('deleted_at');
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'photo_id.required' => 'Выберите фото для верификации.',
            'photo_id.exists'   => 'Фото не найдено или не подходит для верификации (нужна фотография типа verification в статусе pending).',
        ];
    }
}
