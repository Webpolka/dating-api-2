<?php

namespace App\Http\Requests\Api\V1\Web;

use App\Enums\V1\DatingGoal;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

/**
 * Валидация регистрации (POST /api/register).
 *
 * Пакет 5 (рекомендация №6): правила дословно из AuthController::register();
 * хардкод списка dating_goals заменён на enum DatingGoal (тексты ошибок
 * 'in'/'enum' идентичны). Формат 422 — legacy validateJson().
 */
class RegisterRequest extends WebJsonRequest
{
    protected bool $withMessage = true;

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:255'],
            'gender'         => ['required', 'in:male,female'],
            'birth_day'      => ['required', 'integer', 'between:1,31'],
            'birth_month'    => ['required', 'integer', 'between:1,12'],
            'birth_year'     => ['required', 'integer', 'between:1950,2010'],
            'dating_goals'   => ['required', 'array', 'min:1'],
            'dating_goals.*' => ['required', Rule::enum(DatingGoal::class)],
            'city_id'        => ['nullable', 'integer', 'exists:cities,id'],
            'country_id'     => ['nullable', 'integer', 'exists:countries,id'],
            'email'          => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password'       => ['required', 'string', Rules\Password::defaults()],
            'captchaInput'   => ['required', 'string'],
        ];
    }
}
