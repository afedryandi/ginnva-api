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