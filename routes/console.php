<?php

use App\Jobs\V1\ProcessProfileViewsBatch;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// ============================================
// CLOSURE COMMANDS (не schedule, а разовые artisan команды)
// ============================================

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ============================================
// SCHEDULED TASKS (периодические задачи)
// ============================================
// Все периодические задачи — в одном месте.
// Новые задачи добавляй сюда же.
// ============================================

// PROFILE VIEWS — батчинг просмотров анкет
// Каждую минуту обрабатываем буфер просмотров из Redis
// и пишем батчем в БД (вместо 1000 отдельных INSERT'ов)
Schedule::job(new ProcessProfileViewsBatch)
    ->everyMinute()
    ->name('profile-views-batch')
    ->withoutOverlapping()
    ->onOneServer();

// SUBSCRIPTIONS — обработка подписок    
// Для прода — cron entry (выполни на сервере):
// crontab -e
// И добавь строку:
// * * * * * cd /var/www/dating && php artisan schedule:run >> /dev/null 2>&1
// Каждые 5 минут проверяем подписки на продление/истечение.
// FIX (В6): добавлен onOneServer() — на мультисерверном проде два шедулера
// одновременно обрабатывали одни и те же подписки (дубли пушей об истечении;
// от дубля expire() спасал только статус, а вот dispatch пушей дублировался).
Schedule::command('subscriptions:process')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();


// withoutOverlapping(10) - не запускать, если предыдущий запуск еще работает (таймаут 10 мин)
// onOneServer() - критично для прода, если крон крутится на нескольких серверах

Schedule::command('broadcasts:send-scheduled')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();

// BROADCASTS — проверка зависших рассылок (каждые 5 минут)
Schedule::command('broadcasts:check-stuck')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
