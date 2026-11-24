<?php

namespace App\Support\V1;

/**
 * Лёгкий парсер User-Agent без внешних зависимостей.
 *
 * Для прода при необходимости можно заменить на jenssegers/agent
 * или donatj/phpuseragentparser — интерфейс останется тот же.
 *
 * Возвращает:
 *   - device:      человекочитаемое имя (Компьютер/iPad/iPhone/Android)
 *                  — используется в ManageUserSessionsAction.
 *   - browser:     имя браузера (Chrome/Firefox/Safari/...)
 *   - is_mobile:   boolean (true для iPad/iPhone/Android)
 *   - device_type: desktop/mobile/tablet — нормализованный тип для auth-лога.
 *                  iPad → tablet, iPhone/Android → mobile, остальное → desktop.
 *   - os:          Windows/macOS/iOS/Android/Linux/Unknown — для auth-лога.
 */
class UserAgentParser
{
    public static function parse(?string $ua): array
    {
        $device = 'Компьютер';
        $isMobile = false;
        $deviceType = 'desktop';

        if ($ua) {
            if (preg_match('/(iPad)/i', $ua)) {
                $device = 'iPad';
                $isMobile = true;
                $deviceType = 'tablet';
            } elseif (preg_match('/(iPhone)/i', $ua)) {
                $device = 'iPhone';
                $isMobile = true;
                $deviceType = 'mobile';
            } elseif (preg_match('/(Android)/i', $ua)) {
                $device = 'Android';
                $isMobile = true;
                $deviceType = 'mobile';
            }
        }

        $browser = 'Браузер';
        if ($ua) {
            if (preg_match('/(Edg)/i', $ua)) {
                $browser = 'Edge';
            } elseif (preg_match('/(OPR|Opera)/i', $ua)) {
                $browser = 'Opera';
            } elseif (preg_match('/(Chrome|CriOS)/i', $ua)) {
                $browser = 'Chrome';
            } elseif (preg_match('/(Firefox|FxiOS)/i', $ua)) {
                $browser = 'Firefox';
            } elseif (preg_match('/(Safari)/i', $ua)) {
                $browser = 'Safari';
            }
        }

        // OS detection — для user_auth_logs.device_os.
        // Порядок проверок важен: iPad/iPhone → iOS (даже если UA содержит
        // "Mac OS X", это iOS на мобильном устройстве). Android проверяем
        // до Linux, т.к. Android UA содержит "Linux" тоже.
        $os = 'Unknown';
        if ($ua) {
            if (preg_match('/(iPhone|iPad|iPod)/i', $ua)) {
                $os = 'iOS';
            } elseif (preg_match('/(Android)/i', $ua)) {
                $os = 'Android';
            } elseif (preg_match('/(Windows NT)/i', $ua)) {
                $os = 'Windows';
            } elseif (preg_match('/(Mac OS X|Macintosh)/i', $ua)) {
                $os = 'macOS';
            } elseif (preg_match('/(Linux|X11)/i', $ua)) {
                $os = 'Linux';
            }
        }

        return [          
            'device'      => $device,
            'browser'     => $browser,
            'is_mobile'   => $isMobile,
            'device_type' => $deviceType,
            'os'          => $os,
        ];
    }
}
