<?php

use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Isi duration_days untuk booking lama yang dibuat SEBELUM fitur
     * kapasitas slot ada (kolomnya masih NULL) — biar konsisten dengan
     * asumsi Booking::confirmedOverlapCount() bahwa kolom ini selalu
     * terisi begitu dicek kapasitasnya. Booking baru sudah otomatis
     * terisi lewat Booking::booted(), ini cuma backfill data lama.
     */
    public function up(): void
    {
        Booking::whereNull('duration_days')
            ->where('product_ppf', true)
            ->update(['duration_days' => Booking::DEFAULT_DURATION_DAYS_PPF]);

        Booking::whereNull('duration_days')
            ->update(['duration_days' => Booking::DEFAULT_DURATION_DAYS_DEFAULT]);
    }

    public function down(): void
    {
        // Tidak ada yang perlu dibalik — kembali ke NULL tidak berguna
        // (accessor sudah fallback ke default yang sama persis).
    }
};
