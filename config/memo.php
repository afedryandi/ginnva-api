<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Penyetuju dan pencatat pengambilan tambahan memo (2026-11-24)
    |--------------------------------------------------------------------------
    | Pengambilan pertama: disetujui store manager, dicatat staf gudang, lalu memo ditutup (Selesai diserahkan). Pengambilan kedua dan
    | seterusnya (Minta tambahan) harus disetujui, lalu dicatat, oleh akun berjabatan di bawah ini; akses penuh (super admin, direksi) selalu
    | boleh. Nama jabatan mengikuti menu Jabatan di pengaturan pengguna (huruf kecil, spasi ditulis garis bawah). Bisa diganti lewat
    | MEMO_ADDITION_ROLES di .env, dipisah koma, lalu `php artisan config:cache`.
    */
    'addition_roles' => array_values(array_filter(array_map('trim', explode(',', (string) env('MEMO_ADDITION_ROLES', 'admin,spv_finance'))))),

];
