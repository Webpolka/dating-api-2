<?php

namespace App\Http\Requests\Api\V1\Web;

use App\Enums\V1\DatingGoal;
use Illuminate\Validation\Rule;

/**
 * Валидация шага 2 регистрации (POST /api/register/step2).
 *
 * Пакет 5 (рекомендация №6): правила дословно из
 * AuthController::validateStep2(), хардкод списка целей заменён на
 * существующий enum DatingGoal (рекомендация «замена хардкода DatingGoal
 * на enum»). Тексты ошибок 'in' и 'enum' в локолях идентичны, множество
 * значений то же (friends/romantic/family/casual/travel).
 */
class ValidateRegisterStep2Request extends WebJsonRequest
{
    protected bool $withMessage = true;

    public function rules(): array
    {
        return [
            'dating_goals' => ['required', 'array', 'min:1'],
            'dating_goals.*' => ['required', Rule::enum(DatingGoal::class)],
        ];
    }
}
