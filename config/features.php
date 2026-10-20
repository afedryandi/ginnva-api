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

    /*
    |--------------------------------------------------------------------------
    | Persediaan dan HPP di Buku Besar (Keuangan Fase 2)
    |--------------------------------------------------------------------------
    | Mati bawaan. Bila menyala: HPP tiap booking dijurnal otomatis (Debit 5100/5200, Kredit 1130/1131/1132) dan selisih Stok Opname dijurnal
    | ke Beban Kerugian Persediaan (6520). Nyalakan HANYA setelah saldo awal persediaan disinkronkan lewat tombol "Sinkronkan Persediaan" di
    | Rekonsiliasi Sub-Buku, kalau tidak akun persediaan menjadi negatif. Isi FEATURE_INVENTORY_GL=true di .env lalu `php artisan config:cache`.
    */
    'inventory_gl' => (bool) env('FEATURE_INVENTORY_GL', false),

    /*
    |--------------------------------------------------------------------------
    | Pendapatan diakui pada tanggal pekerjaan selesai (Penjualan Fase 3)
    |--------------------------------------------------------------------------
    | Mati bawaan: jurnal pendapatan booking bertanggal hari kasir menyimpan (perilaku lama). Bila menyala: bertanggal Booking::completed_at
    | selama tanggal itu tidak di masa depan dan periodenya masih terbuka (kalau tidak, hari ini, dengan catatan di deskripsi jurnal).
    | Nyalakan setelah kolom completed_at dicek terisi benar dan akuntan setuju. Isi FEATURE_REVENUE_ON_COMPLETION=true di .env.
    */
    'revenue_on_completion' => (bool) env('FEATURE_REVENUE_ON_COMPLETION', false),

];
