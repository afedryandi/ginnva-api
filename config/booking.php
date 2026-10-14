<?php

/**
 * Pengaturan booking (Fase 1 pengerjaan ulang booking, 2026-10-15).
 *
 * show_prices: tampilkan estimasi harga di katalog layanan aplikasi (GET /api/booking/services). Keputusan pemilik
 * 2026-10-15: harga TIDAK ditampilkan ke pelanggan (dikonfirmasi toko), jadi default false dan API tidak mengirim harga
 * sama sekali. Nyalakan dengan BOOKING_SHOW_PRICES=true bila suatu saat ingin menampilkannya lagi.
 */
return [
    'show_prices' => (bool) env('BOOKING_SHOW_PRICES', false),
];
