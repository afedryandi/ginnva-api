<?php

// config/sentry.php
// Konfigurasi minimal sentry/sentry-laravel. Kosongkan SENTRY_LARAVEL_DSN
// di .env untuk menonaktifkan sepenuhnya (tidak akan error kalau kosong).

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // Nama release — memudahkan menandai error muncul di deploy mana.
    'release' => env('SENTRY_RELEASE'),

    'environment' => env('APP_ENV'),

    // Kirim breadcrumb SQL query (berguna untuk debug, non-sensitif —
    // hanya query & durasi, bukan hasil data).
    'breadcrumbs' => [
        'sql_queries' => true,
        'sql_bindings' => false,
    ],

    // Persentase transaksi yang di-trace untuk performance monitoring.
    // 0.2 = 20% — cukup untuk melihat tren tanpa menghabiskan kuota free tier.
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.2),

    'send_default_pii' => false,
];
