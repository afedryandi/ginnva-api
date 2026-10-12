<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tur pengenalan panel admin
    |--------------------------------------------------------------------------
    | Disembunyikan dulu (keputusan 2026-10-12). Kodenya tetap ada; untuk menyalakan lagi isi FEATURE_ADMIN_TOUR=true di
    | .env lalu jalankan `php artisan config:cache`. Saat mati, jendela tur tidak tampil dan tautan "Ulangi tur" di halaman
    | Panduan ikut disembunyikan.
    */
    'admin_tour' => (bool) env('FEATURE_ADMIN_TOUR', false),

];
