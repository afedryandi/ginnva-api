<?php

/**
 * Versi aplikasi mobile (Fase 0 pengerjaan ulang booking, 2026-10-14).
 *
 * - `min`: versi terendah yang boleh memakai API. Build yang MENGIRIM header X-App-Version di bawah ini dijawab 426
 *   (Upgrade Required). Kosong = tidak ada pemaksaan. Build lama yang tidak mengirim header tidak terpengaruh
 *   (tidak bisa dibedakan dari klien lain seperti ginnva-web), jadi pemaksaan hanya bekerja untuk build yang sudah
 *   membawa header ini.
 * - `latest`: versi terbaru di toko aplikasi (hanya untuk imbauan "ada pembaruan").
 * - `store_url`: tautan toko aplikasi untuk tombol "Perbarui".
 */
return [
    'android' => [
        'min' => env('APP_MIN_VERSION_ANDROID'),
        'latest' => env('APP_LATEST_VERSION_ANDROID'),
        'store_url' => env('APP_STORE_URL_ANDROID', 'https://play.google.com/store/apps/details?id=id.ginnva.shield'),
    ],
    'ios' => [
        'min' => env('APP_MIN_VERSION_IOS'),
        'latest' => env('APP_LATEST_VERSION_IOS'),
        'store_url' => env('APP_STORE_URL_IOS'),
    ],
];
