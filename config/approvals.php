<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Email pengajuan baru ke direksi (2026-11-13)
    |--------------------------------------------------------------------------
    | Menyala bawaan: setiap pengajuan baru yang butuh persetujuan (Lembur, Dinas Luar, Izin & Cuti, Koreksi Absen, Permohonan Pembelian,
    | Persetujuan Transaksi) dikirimi email ke pengguna akses penuh yang berhak memutuskannya, berisi tombol ke Pusat Persetujuan.
    | Untuk mematikan isi APPROVAL_EMAIL=false di .env. Tes mematikannya (phpunit.xml) kecuali tes email sendiri.
    */
    'email' => (bool) env('APPROVAL_EMAIL', true),

];
