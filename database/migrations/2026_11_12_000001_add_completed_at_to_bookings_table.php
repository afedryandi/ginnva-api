<?php

use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penjualan Fase 3 (2026-11-12): tanggal pekerjaan selesai pada booking. Diisi otomatis oleh Booking saat status menjadi "completed".
 * Booking yang sudah selesai diisi dari log aktivitas (kejadian pertama status berubah ke completed); yang tidak punya log dibiarkan
 * kosong. Jurnal yang sudah ada tidak disentuh. Aman dijalankan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'completed_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->timestamp('completed_at')->nullable()->after('cancelled_at');
                $table->index('completed_at');
            });
        }

        if (! Schema::hasTable('activity_log')) {
            return;
        }

        $subjectType = (new Booking)->getMorphClass();

        DB::table('bookings')
            ->where('status', 'completed')
            ->whereNull('completed_at')
            ->orderBy('id')
            ->select('id')
            ->chunkById(200, function ($bookings) use ($subjectType) {
                foreach ($bookings as $booking) {
                    $at = DB::table('activity_log')
                        ->where('subject_type', $subjectType)
                        ->where('subject_id', $booking->id)
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(properties, '$.attributes.status')) = 'completed'")
                        ->min('created_at');

                    if ($at) {
                        DB::table('bookings')->where('id', $booking->id)->update(['completed_at' => $at]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'completed_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropIndex(['completed_at']);
                $table->dropColumn('completed_at');
            });
        }
    }
};
