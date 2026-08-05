<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assign installer sekarang bisa lebih dari 1 per booking (lihat
 * booking_installers) — kolom lama bookings.installer_user_id (cuma 1
 * installer) dipindah datanya dulu ke tabel pivot baru, baru dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('bookings')
            ->whereNotNull('installer_user_id')
            ->select('id', 'installer_user_id')
            ->orderBy('id')
            ->chunk(200, function ($bookings) use ($now) {
                $rows = $bookings->map(fn ($b) => [
                    'booking_id' => $b->id,
                    'user_id'    => $b->installer_user_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('booking_installers')->insertOrIgnore($rows);
            });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('installer_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('installer_user_id')->nullable()->after('store_id')
                ->constrained('users')->nullOnDelete();
        });

        // Ambil 1 installer per booking (kalau ada lebih dari 1, cuma yang
        // paling lama di-assign yang di-restore — downgrade memang lossy).
        DB::table('booking_installers')
            ->orderBy('booking_id')
            ->orderBy('created_at')
            ->get()
            ->groupBy('booking_id')
            ->each(function ($rows, $bookingId) {
                DB::table('bookings')->where('id', $bookingId)
                    ->update(['installer_user_id' => $rows->first()->user_id]);
            });
    }
};
