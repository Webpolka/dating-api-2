<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Web\SubmitVerificationRequest;
use App\Http\Resources\Api\V1\Web\VerificationStatusResource;
use App\Models\Photo;
use App\Models\Verification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;

/**
 * Web API верификации пользователя.
 *
 * Флоу:
 *   1. GET  /api/verification/status — текущий статус (is_verified + active pending).
 *   2. POST /api/verification/code   — бек генерит 6-символьный код, кладёт в Redis
 *      (verify:code:{user_id}, TTL 10 мин), возвращает { code, expires_in }.
 *      Юзер делает селфи с этим кодом на бумажке/жестом.
 *   3. Юзер грузит verification-фото (через существующий PhotoController/OnboardingController,
 *      Photo type=verification, status=pending — без модерации, модератор смотрит
 *      через Verification flow).
 *   4. POST /api/verification/submit { photo_id } — бек берёт код из Redis, создаёт
 *      Verification (status=pending, code, photo_id), удаляет код из Redis.
 *      UNIQUE partial index (user_id WHERE status=pending) защищает от дублей.
 *
 * Код живёт в Redis, не в БД: если юзер передумал — запись не создаётся.
 * В Verification.code попадает только использованный код (для аудита/истории).
 */
class VerificationController extends Controller
{
    /** Redis-ключ для хранения одноразового кода (TTL = CODE_TTL_MINUTES). */
    private function codeKey(int $userId): string
    {
        return "verify:code:{$userId}";
    }

    /**
     * GET /api/verification/status — текущий статус верификации юзера.
     */
    public function status(Request $request): JsonResponse
    {
        return (new VerificationStatusResource($request->user()))
            ->response()
            ->setEncodingOptions(JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/verification/code — получить одноразовый код верификации.
     *
     * Возвращает { code, expires_in (сек), expires_at (ISO) }.
     * Если уже есть активная pending-заявка — 422 "У вас уже есть заявка на рассмотрении".
     * Если юзер уже верифицирован — 422 "Аккаунт уже верифицирован".
     *
     * Если код в Redis ещё жив — возвращаем его (повторный запрос не регенерит,
     * чтобы юзер не путался, перерисовывая записку).
     */
    public function requestCode(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->is_verified) {
            throw ValidationException::withMessages([
                'verification' => ['Аккаунт уже верифицирован.'],
            ]);
        }

        $active = Verification::findActiveForUser($user->id);
        if ($active) {
            throw ValidationException::withMessages([
                'verification' => ['У вас уже есть заявка на рассмотрении.'],
            ]);
        }

        $key = $this->codeKey($user->id);

        try {
            $existing = Redis::get($key);
            if ($existing) {
                $ttl = (int) Redis::ttl($key);
                $code = $existing;
            } else {
                $code = Verification::generateCode();
                Redis::setex($key, Verification::CODE_TTL_MINUTES * 60, $code);
                $ttl = Verification::CODE_TTL_MINUTES * 60;
            }
        } catch (\Throwable $e) {
            Log::warning('Verification code Redis failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Не удалось сгенерировать код. Попробуйте позже.',
            ], 503);
        }

        return response()->json([
            'code'       => $code,
            'expires_in' => $ttl,
            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
        ]);
    }

    /**
     * POST /api/verification/submit — подать заявку на верификацию.
     *
     * Body: { photo_id: int }
     *
     * Валидация (SubmitVerificationRequest):
     *  - photo_id существует, принадлежит юзеру, type=verification, status=pending.
     *
     * Доп. проверки в контроллере:
     *  - is_verified → 422 (уже верифицирован).
     *  - есть активный pending → 422 (дубликат заявки).
     *  - код в Redis живой → если нет, 422 "Код истёк, получите новый".
     *
     * Создаёт Verification (status=pending, code, photo_id), удаляет код из Redis.
     */
    public function submit(SubmitVerificationRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->is_verified) {
            throw ValidationException::withMessages([
                'verification' => ['Аккаунт уже верифицирован.'],
            ]);
        }

        // Двойная защита от дублей (UNIQUE partial index + явная проверка).
        // Явная проверка даёт человекочитаемое 422 вместо SQL-ошибки.
        if (Verification::findActiveForUser($user->id)) {
            throw ValidationException::withMessages([
                'verification' => ['У вас уже есть заявка на рассмотрении.'],
            ]);
        }

        $key = $this->codeKey($user->id);
        try {
            $code = Redis::get($key);
        } catch (\Throwable $e) {
            Log::warning('Verification submit Redis failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Сервис временно недоступен. Попробуйте позже.',
            ], 503);
        }

        if (!$code) {
            throw ValidationException::withMessages([
                'verification' => ['Код верификации истёк. Получите новый код и попробуйте снова.'],
            ]);
        }

        $photoId = (int) $request->validated()['photo_id'];

        try {
            $verification = Verification::create([
                'user_id'  => $user->id,
                'photo_id' => $photoId,
                'code'     => $code,
                'status'   => Verification::STATUS_PENDING,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Race condition: два submit'а одновременно проскочили проверку выше.
            return response()->json([
                'message' => 'У вас уже есть заявка на рассмотрении.',
            ], 422);
        }

        // Код использован — чистим Redis, чтобы повторный submit не прошёл
        // с тем же кодом (теперь только через новый POST /code).
        try {
            Redis::del($key);
        } catch (\Throwable) {
            // не критично — TTL 10 мин всё почистит
        }

        // Сброс бейджа сайдбара админки (pending-верификации).
        \Illuminate\Support\Facades\Cache::forget('admin_sidebar_stats');
        \Illuminate\Support\Facades\Cache::forget('admin_dashboard_live_v2');

        return response()->json([
            'message'        => 'Заявка отправлена на модерацию.',
            'verification'   => [
                'id'         => $verification->id,
                'status'     => $verification->status,
                'code'       => $verification->code,
                'created_at' => $verification->created_at?->toIso8601String(),
                'photo'      => [
                    'id'         => $photoId,
                    'thumb_url'  => $verification->photo?->thumb_url,
                    'medium_url' => $verification->photo?->medium_url,
                ],
            ],
        ], 201);
    }
}
