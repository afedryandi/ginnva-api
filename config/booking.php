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

    // Fase 3A: bila true, booking baru bisa diselesaikan hanya setelah Quality Check terbaru LULUS. Bawaan false supaya
    // aplikasi staf lama (yang belum punya layar QC) tetap bisa menyelesaikan booking; nyalakan setelah build baru beredar.
    'qc_required' => (bool) env('BOOKING_QC_REQUIRED', false),

    // Fase 3B: bila true, booking baru bisa diselesaikan hanya setelah serah terima dicatat. Nyalakan bersama qc_required
    // setelah build mobile baru beredar (aplikasi lama tidak punya layar serah terima).
    'handover_required' => (bool) env('BOOKING_HANDOVER_REQUIRED', false),

    // Fase 5: target lama booking pending sampai dikonfirmasi (jam), dasar laporan SLA. Sama dengan pengingat booking pending (4 jam).
    'confirm_sla_hours' => (int) env('BOOKING_CONFIRM_SLA_HOURS', 4),

    // Fase 5: masa simpan foto kendaraan (bulan) sejak tanggal layanan; lihat bookings:prune-photos. Keputusan pemilik: 24 bulan.
    'photo_retention_months' => (int) env('BOOKING_PHOTO_RETENTION_MONTHS', 24),
];
