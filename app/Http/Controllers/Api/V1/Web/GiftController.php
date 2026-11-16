<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Actions\V1\Web\SendGiftAction;
use App\Exceptions\V1\InsufficientCreditsException;
use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Models\User;
use App\Http\Requests\Api\V1\Web\SendGiftRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GiftController extends Controller
{
    // Получить каталог подарков
    public function index()
    {
        // Пакет 5 (рекомендация №8): каталог — справочник, меняется только из
        // админки. Раньше каждый запрос (каждое открытие модалки подарков)
        // делал полный SELECT gifts. Теперь 10 минут кэша (ключ и инвалидация —
        // в Gift::booted), админ обновил справочник → ключ сброшен → перечитано.
        $gifts = Cache::remember(Gift::CACHE_KEY, 600, function () {
            return Gift::where('is_active', true)
                ->get()
                ->groupBy('type');
        });

        return response()->json(['gifts' => $gifts]);
    }

    // Отправить подарок
    public function store(SendGiftRequest $request, User $user, SendGiftAction $action)
    {
        $validated = $request->validated();

        $sender = $request->user();
        $receiver = $user;

        if ($sender->id === $receiver->id) {
            return response()->json(['success' => false, 'message' => 'Нельзя дарить подарки себе'], 400);
        }

        $sender->load('balance');
        
        if (!$sender->balance) {
            return response()->json([
                'success' => false, 
                'message' => 'У вас нет счета. Пополните баланс.', 
            ], 403);
        }

        $gift = Gift::findOrFail($validated['gift_id']);

        try {
            // Списание кредитов + UserGift-запись (транзакция) — в Action.
            // Пакет 5 (рекомендация №3): ловим ТОЛЬКО доменное исключение
            // «мало кредитов» → честный 402 с пользовательским текстом.
            $action->execute($sender, $receiver, $gift, $validated);
        } catch (InsufficientCreditsException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 402);
        } catch (\Throwable $e) {
            // Раньше ЛЮБАЯ ошибка (БД, Redis) улетала клиенту как 402 с текстом
            // внутреннего исключения — утечка внутренностей + неверный статус.
            // Теперь: детали в лог, наружу — фиксированный текст, 500.
            \Illuminate\Support\Facades\Log::error('GiftController: отправка подарка не удалась', [
                'sender_id'   => $sender->id,
                'receiver_id' => $receiver->id,
                'gift_id'     => $gift->id,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Не удалось отправить подарок. Попробуйте позже.',
            ], 500);
        }

        return response()->json([
            'success' => true, 
            'message' => 'Подарок успешно отправлен!'
        ]);
    }

       /**
     * Удалить подарок из коллекции получателя.
     *
     * Endpoint: DELETE /api/gifts/{giftId}
     *
     * Бизнес-логика:
     *   - Удаление — ЛИЧНОЕ ДЕЙСТВИЕ получателя.
     *   - Отправитель НЕ получает уведомления.
     *   - В user_events НЕ создаём запись (это не событие для ленты).
     *   - Просто удаляем запись из user_gifts.
     *
     * 404 если:
     *   - подарок не существует
     *   - подарок принадлежит другому пользователю (не $user)
     */
    public function destroy(Request $request, int $giftId)
    {
        $user = $request->user();

        // Ищем подарок СРЕДИ подарков, полученных текущим юзером.
        // belongsTo проверка — через связь receivedGifts() в User.
        $userGift = $user->giftsReceived()->where('id', $giftId)->first();

        if (!$userGift) {
            return response()->json([
                'success' => false,
                'message' => 'Подарок не найден или не принадлежит вам.',
            ], 404);
        }

        // Удаляем без уведомлений и user_events.
        $userGift->delete();

        return response()->json([
            'success' => true,
            'message' => 'Подарок удалён из вашей коллекции.',
        ]);
    }
}