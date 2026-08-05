<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Mengizinkan frontend Next.js (Vercel) di domain ginnva.id untuk
    | mengakses API Laravel ini (api.ginnva.id) tanpa diblok browser.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://ginnva.id',
        'https://www.ginnva.id',
        'http://localhost:3000',
        // Expo web dev server (ginnva-mobile) — dipakai buat preview cepat
        // di browser saat development, port default Expo Router.
        'http://localhost:8081',
        'http://localhost:19006',
    ],

    // Untuk domain preview Vercel yang berubah-ubah, misal:
    // ginnva-web-git-fix-warranty-username.vercel.app
    'allowed_origins_patterns' => [
        '#^https://ginnva-web.*\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];