<?php

/**
 * Marketing/Konten Fase 1 "Lead terkelola" (2026-10-23).
 *
 * lead_response_sla_hours: target waktu respons pertama untuk lead (quotation, inquiry produk, kemitraan), dalam jam. Dipakai laporan
 * "Dasbor Lead" (persentase lead yang direspons dalam target) dan pengingat lead telat (leads:remind-stale). Bawaan 24 jam, sama
 * dengan ambang pengingat quotation yang sudah ada; perketat lewat LEAD_RESPONSE_SLA_HOURS bila tim sudah siap.
 */
return [
    'lead_response_sla_hours' => max(1, (int) env('LEAD_RESPONSE_SLA_HOURS', 24)),

    // Fase 5: batas kiriman promosi (kampanye) per 7 hari ke pelanggan; mencegah pelanggan kebanjiran lalu mematikan semua notifikasi.
    'promo_max_campaigns_per_week' => max(1, (int) env('PROMO_MAX_CAMPAIGNS_PER_WEEK', 2)),

    // Notifikasi Fase 4 (keputusan pemilik 2026-10-21): promosi tidak dikirim pada jam tenang (bawaan 21:00 sampai 08:00 WIB).
    // Hanya berlaku untuk kategori promosi; notifikasi booking/transaksi dan informasi penting tidak terpengaruh.
    'promo_quiet_hours' => [
        'enabled'  => filter_var(env('PROMO_QUIET_HOURS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'start'    => env('PROMO_QUIET_HOURS_START', '21:00'),
        'end'      => env('PROMO_QUIET_HOURS_END', '08:00'),
        'timezone' => env('PROMO_QUIET_HOURS_TIMEZONE', 'Asia/Jakarta'),
    ],
];
