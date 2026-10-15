<?php

/**
 * Quotation (lead penawaran) dari pelanggan lewat web dan aplikasi mobile SELALU masuk ke satu toko, tidak bergantung toko yang
 * dipilih/dikirim klien (keputusan pemilik 2026-10-15). Toko dicari berdasarkan NAMA (tanpa membedakan huruf besar/kecil).
 * Bila tidak ada toko dengan nama itu, quotation tetap diterima tanpa toko (dan dicatat di log), bukan ditolak.
 */
return [
    'store_name' => env('QUOTATION_STORE_NAME', 'Ginnva House'),
];
