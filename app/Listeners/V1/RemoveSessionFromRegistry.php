<?php

namespace App\Listeners\V1;

use App\Services\V1\SessionRegistry;
use Illuminate\Auth\Events\Logout;
use Throwable;

/**
 * Убрать сессию из Redis-реестра при явном логауте (ПАКЕТ 6).
 *
 * Logout-событие стреляет в SessionGuard::logout() ДО
 * session()->invalidate() — id сессии ещё жив, поэтому забираем его
 * прямо из хранилища и вычищаем запись реестра сразу, не дожидаясь
 * TTL/ленивой чистки (таб «Активные устройства» актуален мгновенно).
 *
 * Не критично при сбое: призрак выпилится лениво (isFresh/handler->read)
 * при следующем чтении реестра.
 *
 * Авто-дискаверится (type-hint на handle, как RevokePurchasedItems).
 */
class RemoveSessionFromRegistry
{
    public function __construct(private readonly SessionRegistry $registry)
    {
    }

    public function handle(Logout $event): void
    {
        try {
            $user = $event->user;

            if ($user === null) {
                return;
            }

            $sessionId = (string) session()->getId();

            if ($sessionId !== '') {
                $this->registry->forget((int) $user->getAuthIdentifier(), $sessionId);
            }
        } catch (Throwable) {
            // Реестр вспомогательный: ошибка чистки не ломает логаут.
        }
    }
}
