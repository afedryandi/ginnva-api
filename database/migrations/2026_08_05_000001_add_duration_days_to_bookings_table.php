<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dipakai fitur kapasitas slot booking — berapa hari kerja booking ini
     * makan tanggal instalasi (PPF butuh beberapa hari, Kaca Film biasanya
     * 1 hari). Nullable karena diisi OTOMATIS lewat Booking::booted()
     * (default per jenis produk) kalau staff tidak override manual saat
     * approve — lihat App\Models\Booking.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedTinyInteger('duration_days')->nullable()->after('preferred_time');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('duration_days');
        });
    }
};
