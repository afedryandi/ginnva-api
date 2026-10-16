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
];
