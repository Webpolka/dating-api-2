<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Web\StoreSwipeRequest;
use App\Models\Swipe;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SwipeController extends Controller
{
    public function store(StoreSwipeRequest $request, User $user)
    {
        /** @var \App\Models\User $authUser */
        $authUser = $request->user();

        // Роут под auth:sanctum — проверку !$authUser не делаем (мёртвый код).
        if ($authUser->id === $user->id) {
            abort(403);
        }

        $type = $request->validated()['type'];

        // FIX 1 (функциональный баг): мэтч НИКОГДА не создавался — API
        // возвращал is_match=true, но UserMatch::create не вызывался нигде,
        // кроме сидера. Из-за этого не работали: создание чата при мэтче,
        // уведомления NewMatch, исключение пары из ленты (UserMatchObserver).
        //
        // FIX 2 (гонка): upsert свайпа + проверка взаимности + создание
        // мэтча выполняются в ОДНОЙ транзакции — двойной клик или
        // параллельные запросы не ломают консистентность.
        $isMatch = DB::transaction(function () use ($authUser, $user, $type) {
            $this->upsertSwipe($authUser->id, $user->id, $type);

            if (!in_array($type, [Swipe::TYPE_LIKE, Swipe::TYPE_SUPERLIKE], true)) {
                return false;
            }

            // Встречный положительный (не отмотанный) свайп?
            $reversePositive = Swipe::query()
                ->where('user_id', $user->id)
                ->where('target_user_id', $authUser->id)
                ->positive()  // like|superlike (scope модели)
                ->active()    // rewinded_at IS NULL (scope модели)
                ->exists();

            if (!$reversePositive) {
                return false;
            }

            // createMatch: приводит пару к правилу user1_id < user2_id,
            // устойчив к дубликатам (unique + реактивация unmatched-мэтчей).
            // UserMatchObserver создаст чат, отправит уведомления и уберёт
            // пару из лент обоих юзеров.
            UserMatch::createMatch($authUser->id, $user->id);

            return true;
        });

        return response()->json([
            'success' => true,
            'is_match' => $isMatch,
            'current_swipe' => $type
        ]);
    }

    /**
     * Создаёт или обновляет свайп (updateOrCreate — запускает обсерверы:
     * SwipeObserver инвалидирует кэш ленты свайпера и таргета).
     *
     * Гонка двойного клика: два параллельных запроса могут оба пройти
     * SELECT, не найти запись и оба пойти в INSERT — второй упадёт по
     * unique(user_id, target_user_id). Вложенная DB::transaction = SAVEPOINT:
     * откатываем только неудачную вставку и повторяем — внешняя транзакция
     * остаётся рабочей.
     */
    private function upsertSwipe(int $userId, int $targetUserId, string $type): Swipe
    {
        $attributes = ['user_id' => $userId, 'target_user_id' => $targetUserId];
        $values = ['type' => $type, 'rewinded_at' => null];

        try {
            return DB::transaction(fn (): Swipe => Swipe::updateOrCreate($attributes, $values));
        } catch (QueryException) {
            return DB::transaction(fn (): Swipe => Swipe::updateOrCreate($attributes, $values));
        }
    }
}
