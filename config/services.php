<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mock Acquiring (симуляция банка)
    |--------------------------------------------------------------------------
    |
    | Параметры симулятора эквайринга. Вынесены в конфиг (FIX пакета «Деньги»):
    |  - вероятности успеха в процентах (0-100) — чтобы в тестах ставить 100/0
    |    и получать детерминированные сценарии вместо rand() в коде;
    |  - latency_ms — имитация сетевой задержки. В тестах ставить 0.
    |
    | ВАЖНО: latency влияет на место вызова банка. Вызовы MockAcquiringService
    | должны выполняться ВНЕ DB-транзакций (см. ProcessRefundJob/ProcessSyncJob),
    | иначе воркер держит lockForUpdate и соединение с БД всю «задержку банка».
    */
    'mock_acquiring' => [
        'payment_success_rate' => env('MOCK_ACQUIRING_PAYMENT_RATE', 90),
        'refund_success_rate'  => env('MOCK_ACQUIRING_REFUND_RATE', 90),
        'sync_success_rate'    => env('MOCK_ACQUIRING_SYNC_RATE', 80),
        'latency_ms'           => env('MOCK_ACQUIRING_LATENCY_MS', 1000),
    ],

];
