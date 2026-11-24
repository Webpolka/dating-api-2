<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserAuthLog;
use Illuminate\Database\Seeder;

class UserAuthLogsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🔐 Генерируем историю заходов...');

        UserAuthLog::truncate();

        $users = User::where('role', 'user')->get();

        $devices = [
            ['os' => 'Windows 11',   'type' => 'desktop', 'agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
            ['os' => 'macOS',       'type' => 'desktop', 'agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
            ['os' => 'Android 13',  'type' => 'mobile',  'agent' => 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36'],
            ['os' => 'iOS 17',      'type' => 'mobile',  'agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'],
            ['os' => 'Linux',       'type' => 'desktop', 'agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Firefox/121.0'],
            ['os' => 'iPadOS 17',   'type' => 'tablet',  'agent' => 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'],
        ];

        // Реалистичные публичные IP (без приватных 192.168.x.x — они не бывают
        // в реальных auth-логах, т.к. $request->ip() в проде видит публичный IP
        // клиента, а не внутренний адрес балансировщика при правильном доверии).
        $ips = ['95.153.132.10', '178.66.50.22', '45.12.30.5', '185.220.101.1', '213.87.144.22', '77.51.18.93'];

        $insertData = [];

        foreach ($users as $user) {
            $logsCount = rand(2, 5);

            for ($i = 0; $i < $logsCount; $i++) {
                $device = $devices[array_rand($devices)];
                $date = now()->subDays(rand(1, 30))->subHours(rand(1, 23));

                // ~15% попыток — неудачные (забытый пароль / опечатка / brute-force).
                // Раньше сидер генерировал ТОЛЬКО успешные логи → таб «Неудачные»
                // в админке показывал 0, counts.failed всегда был 0, антифрод-
                // контекст был невидим в сид-данных.
                $isSuccessful = rand(1, 100) > 15;

                $insertData[] = [
                    'user_id'       => $user->id,
                    'ip_address'    => $ips[array_rand($ips)],
                    'user_agent'    => $device['agent'],
                    'device_os'     => $device['os'],
                    'device_type'   => $device['type'],
                    'is_successful' => $isSuccessful,
                    'created_at'    => $date,
                    'updated_at'    => $date,
                ];
            }
        }

        // Массовая вставка (молниеносно)
        UserAuthLog::insert($insertData);

        $total = count($insertData);
        $failed = count(array_filter($insertData, fn($row) => !$row['is_successful']));
        $this->command->info("✅ История заходов сгенерирована ({$total} записей, из них {$failed} неудачных).");
    }
}
