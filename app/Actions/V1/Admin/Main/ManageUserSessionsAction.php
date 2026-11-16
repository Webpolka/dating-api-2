<?php

namespace App\Actions\V1\Admin\Main;

use App\Models\AdminLog;
use App\Models\User;
use App\Services\V1\SessionRegistry;
use App\Support\V1\UserAgentParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Сессии юзера в админке: список + kill одной + kill всех.
 *
 * ПАКЕТ 6 (рекомендация №7): прямой SQL по таблице sessions заменён на
 * SessionRegistry — механика работает при ЛЮБОМ session-драйвере.
 * При SESSION_DRIVER=database (сейчас) поведение идентично прежнему
 * (источник — та же таблица sessions, форматы 1:1), при переходе на
 * redis реестр станет источником истины сам (см. SessionRegistry).
 */
class ManageUserSessionsAction
{
    public function __construct(private readonly SessionRegistry $registry)
    {
    }

    /**
     * Список активных сессий пользователя (готовый для UI).
     *
     * @return array<int, array{
     *     id: string,
     *     ip: string|null,
     *     device: string,
     *     browser: string,
     *     is_mobile: bool,
     *     last_activity: string,
     *     exact_time: string
     * }>
     */
    public function getUserSessions(User $user): array
    {
        $sessions = $this->registry->allFor($user->id);

        return array_map(function (array $session) {
            $ua = UserAgentParser::parse($session['user_agent']);

            // tz-aware: используем app timezone (Asia/Moscow по config/app.php)
            $time = Carbon::createFromTimestamp($session['last_activity'], config('app.timezone'));

            return [
                'id'            => $session['id'],
                'ip'            => $session['ip_address'],
                'device'        => $ua['device'],
                'browser'       => $ua['browser'],
                'is_mobile'     => $ua['is_mobile'],
                'last_activity' => $time->diffForHumans(),
                'exact_time'    => $time->format('d.m.Y H:i:s'),
            ];
        }, $sessions);
    }

    /**
     * Завершить конкретную сессию юзера.
     */
    public function killSession(User $user, string $sessionId, User $admin): bool
    {
        $meta = null;

        // Транзакция гарантирует: сессия убита И лог записан. Иначе откат.
        // destroyOne внутри проверяет владение (нельзя убить чужую) и
        // возвращает метаданные {id, ip} для лога; в database-режиме удаляет
        // строку таблицы в этой же транзакции — семантика прежнего кода.
        DB::transaction(function () use (&$meta, $user, $sessionId, $admin) {
            $meta = $this->registry->destroyOne($user->id, $sessionId);

            if ($meta !== null) {
                $after = [
                    'status'      => 'killed',
                    'killed_by'   => $admin->id,
                    'killed_at'   => now()->toDateTimeString(),
                    'context'     => [
                        'user_id'     => $user->id,
                        'session_id' => $meta['id'],
                        'ip_address' => $meta['ip_address'],
                        'admin_id'   => $admin->id,
                    ],
                ];

                AdminLog::record('user.session_killed', $user, $admin, null, $after, participants: [$user->id]);
            }
        });

        return $meta !== null;
    }

    /**
     * Завершить ВСЕ сессии юзера (кнопка паники).
     *
     * @return int Количество завершённых сессий
     */
    public function killAllSessions(User $user, User $admin): int
    {
        $deletedCount = 0;

        DB::transaction(function () use ($user, $admin, &$deletedCount) {
            // В database-режиме это прежний DELETE ... WHERE user_id = ?
            // с count'ом удалённых строк — внутри транзакции с логом.
            $deletedCount = $this->registry->destroyAllFor($user->id);

            if ($deletedCount > 0) {
                $after = [
                    'status'     => 'all_killed',
                    'killed_by'  => $admin->id,
                    'killed_at'  => now()->toDateTimeString(),
                    'context'    => [
                        'user_id'      => $user->id,
                        'admin_id'     => $admin->id,
                        'killed_count' => $deletedCount,
                    ],
                ];

                AdminLog::record('user.all_sessions_killed', $user, $admin, null, $after, participants: [$user->id]);
            }
        });

        return $deletedCount;
    }
}
