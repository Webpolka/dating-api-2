<?php

namespace App\Actions\V1\Web;

use App\Exceptions\V1\InsufficientCreditsException;
use App\Models\Gift;
use App\Models\User;
use App\Models\UserGift;
use Illuminate\Support\Facades\DB;

/**
 * Отправка подарка от юзера юзеру (user-API).
 *
 * Извлечено из Api\V1\GiftController::store (S5-7).
 * Контроллер оставляет себе: route-model резолюцию получателя, валидацию
 * (SendGiftRequest), guard-проверки (self-gift, отсутствие счёта), резолюцию
 * подарка (findOrFail → 404) и формирование JSON-ответов (в т.ч. 402 при
 * недостатке кредитов — исключение из транзакции пробрасывается наверх).
 */
class SendGiftAction
{
    /**
     * Списать кредиты у отправителя и создать UserGift-запись.
     *
     * Атомарно: если кредитов недостаточно (conditional update не затронул
     * строку) — бросает \Exception, транзакция откатывается.
     *
     * @param  array{gift_id: int, message?: string|null}  $validated  Валидированные данные запроса.
     *
     * @throws InsufficientCreditsException «Недостаточно кредитов для совершения покупки.»
     *                                      (Пакет 5, рекомендация №3: доменное исключение вместо голого \Exception —
     *                                      контроллер отличает «мало денег» от внутренней ошибки и не льёт
     *                                      getMessage() внутренних исключений наружу.)
     */
    public function execute(User $sender, User $receiver, Gift $gift, array $validated): void
    {
        DB::transaction(function () use ($sender, $receiver, $gift, $validated) {
            $updated = $sender->balance()
                ->where('credits', '>=', $gift->price)
                ->update([
                    'credits' => DB::raw('credits - '.$gift->price),
                ]);

            if (! $updated) {
                throw new InsufficientCreditsException;
            }

            UserGift::create([
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
                'gift_id' => $gift->id,
                'snapshot_name' => $gift->name,
                'snapshot_image_url' => $gift->getRawOriginal('image_url'),
                'snapshot_price' => $gift->price,
                // FIX (найдено тестом GiftFlowTest): сообщение опционально —
                // без '?? null' подарок БЕЗ message падал 500
                // («Undefined array key "message"» → ErrorException).
                'message' => $validated['message'] ?? null,
                'is_private' => false,
                'is_read' => false,
            ]);
        });
    }
}
