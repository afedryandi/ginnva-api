<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Masa berlaku "Tandai Ditinjau"
    |--------------------------------------------------------------------------
    | Baris "Perlu Perhatian" (bahan baku, barang habis pakai, aset) yang ditandai ditinjau disembunyikan selama beberapa hari ini,
    | lalu muncul lagi. Baris juga muncul lagi lebih cepat bila datanya berubah atau, untuk bahan baku, bila batchnya kedaluwarsa
    | sesudah ditinjau (audit Dashboard Inventaris, 2026-11-02).
    */
    'review_valid_days' => (int) env('INVENTORY_REVIEW_VALID_DAYS', 7),
];
