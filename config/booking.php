<?php

/**
 * Pengaturan booking (Fase 1-2 pengerjaan ulang booking, 2026-10-15/16).
 *
 * show_prices: tampilkan estimasi harga di katalog layanan aplikasi (GET /api/booking/services). Keputusan pemilik
 * 2026-10-15: harga TIDAK ditampilkan ke pelanggan (dikonfirmasi toko), jadi default false dan API tidak mengirim harga
 * sama sekali. Nyalakan dengan BOOKING_SHOW_PRICES=true bila suatu saat ingin menampilkannya lagi.
 *
 * light_slots: slot jam kedatangan layanan ringan (Detailing, Premium Wash). Teksnya HARUS sama dengan yang dikirim aplikasi
 * pada preferred_time. light_capacity_default: kapasitas per slot bila toko belum mengisi "Kapasitas Layanan Ringan per Jam".
 */
return [
    'show_prices' => (bool) env('BOOKING_SHOW_PRICES', false),

    'light_slots' => [
        '09:00 - 11:00',
        '11:00 - 13:00',
        '13:00 - 15:00',
        '15:00 - 17:00',
    ],

    'light_capacity_default' => (int) env('BOOKING_LIGHT_CAPACITY_DEFAULT', 2),
];
