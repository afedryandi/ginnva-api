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

    /*
    |--------------------------------------------------------------------------
    | Jadwal Kerja (shift dan pola mingguan)
    |--------------------------------------------------------------------------
    | Dimatikan (keputusan pemilik 2026-10-17): Ginnva tidak memakai shift, karyawan mengikuti jam operasional toko. Menu Shift,
    | Jadwal Kerja, dan Kalender Jadwal di panel serta layar Jadwal Kerja di aplikasi staf disembunyikan; data dan kodenya tetap ada.
    | Absensi, telat, dan gaji tetap benar tanpa jadwal (jatuh ke jam buka toko). Untuk menyalakan lagi isi FEATURE_WORK_SCHEDULES=true di
    | .env lalu jalankan `php artisan config:cache`.
    */
    'work_schedules' => (bool) env('FEATURE_WORK_SCHEDULES', false),

];
