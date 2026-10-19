<?php

/**
 * Batas kepegawaian yang berasal dari peraturan (Karyawan Fase 3, 2026-10-31). Disimpan di sini, bukan tertulis di kode, karena aturan
 * ketenagakerjaan masih bisa berubah (putusan MK 168/PUU-XXI/2023). Angka mengikuti ringkasan sumber sekunder dan belum diverifikasi ke
 * teks resmi.
 */
return [
    // Masa berlaku Surat Peringatan dalam bulan (PP 35/2021 Pasal 52, kecuali diatur lain di Peraturan Perusahaan).
    'warning_validity_months' => (int) env('HR_WARNING_VALIDITY_MONTHS', 6),

    // Lama maksimal PKWT termasuk perpanjangan, dalam tahun, dihitung dari tanggal mulai kerja.
    'pkwt_max_years' => (int) env('HR_PKWT_MAX_YEARS', 5),

    // Peringatan masa percobaan hampir berakhir, dalam hari.
    'probation_warning_days' => (int) env('HR_PROBATION_WARNING_DAYS', 14),
];
