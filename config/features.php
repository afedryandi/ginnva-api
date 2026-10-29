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

    /*
    |--------------------------------------------------------------------------
    | Daftar harga kaca film membaca luas kaca dari database (Master Data Fase 2)
    |--------------------------------------------------------------------------
    | Mati bawaan: daftar merek, tipe, dan luas kaca (m²) di kalkulator Price List dibaca dari Google Sheet "Data Ukuran" seperti
    | sebelumnya. Bila menyala: dibaca dari tabel vehicle_glass_areas (menu Master Data > Luas Kaca). Nyalakan HANYA setelah impor dari
    | Sheet dicek lengkap dan benar. Harga produk (sheet "Harga") tidak ikut berpindah. Isi FEATURE_PRICELIST_FROM_DATABASE=true di .env.
    */
    'pricelist_from_database' => (bool) env('FEATURE_PRICELIST_FROM_DATABASE', false),

    /*
    |--------------------------------------------------------------------------
    | Modul Keuangan buatan sendiri (keputusan 2026-10-29: pembukuan memakai Accurate 5)
    |--------------------------------------------------------------------------
    | Mati bawaan: klaster Keuangan (jurnal, kas, anggaran, hutang/piutang, laporan keuangan) tidak tampil di panel, endpoint
    | /finance di aplikasi staf ditolak, jadwal otomatis keuangan tidak jalan, dan fitur lain (booking, refund, DP, penggajian,
    | pembelian, stok terbuang, penyusutan) TIDAK membuat jurnal. Kode dan tabel tetap ada; data lama tidak dihapus.
    | Untuk menyalakan lagi isi FEATURE_FINANCE=true di .env. Tes menyalakannya (phpunit.xml) supaya tes keuangan tetap berjalan.
    */
    'finance' => (bool) env('FEATURE_FINANCE', false),

    /*
    |--------------------------------------------------------------------------
    | Menu HR yang disembunyikan (keputusan pemilik 2026-10-29)
    |--------------------------------------------------------------------------
    | Mati bawaan; kode dan data tetap ada, dan menyala lagi dengan mengisi true di .env.
    |   FEATURE_K3                  Jenis APD, Pembagian APD, dan Kejadian K3 (belum ada kewajiban K3).
    |   FEATURE_PERFORMANCE_REVIEWS Penilaian Kinerja (skor per karyawan per periode).
    |   FEATURE_EMPLOYEE_TRAINING   Pelatihan Karyawan (catatan sertifikat karyawan; sertifikat installer tetap di Kompetensi Installer).
    | Ikut tersembunyi: layar "K3 & Penilaian" di aplikasi staf, butir terkait di Dasbor HR, dan panduannya.
    */
    'k3' => (bool) env('FEATURE_K3', false),
    'performance_reviews' => (bool) env('FEATURE_PERFORMANCE_REVIEWS', false),
    'employee_training' => (bool) env('FEATURE_EMPLOYEE_TRAINING', false),

    /*
    | Menu Karyawan lain yang disembunyikan (audit modul Karyawan, 2026-10-29), mati bawaan:
    |   FEATURE_SALARY_GRADES         Struktur & Skala Upah (dan pilihan Golongan Upah di form User).
    |   FEATURE_EMPLOYEE_CHECKLISTS   Checklist Masuk & Keluar dan Template Checklist (onboarding/offboarding).
    |   FEATURE_INSTALLER_UTILIZATION Utilisasi Installer (jam pekerjaannya hanya estimasi; Durasi Servis memakai jam nyata dari SPK).
    */
    'salary_grades' => (bool) env('FEATURE_SALARY_GRADES', false),
    'employee_checklists' => (bool) env('FEATURE_EMPLOYEE_CHECKLISTS', false),
    'installer_utilization' => (bool) env('FEATURE_INSTALLER_UTILIZATION', false),

];
