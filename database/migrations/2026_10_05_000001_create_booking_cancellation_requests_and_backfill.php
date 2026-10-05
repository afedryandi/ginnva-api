<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit alur Booking 2026-10-05:
 * - booking_cancellation_requests: customer mengajukan pembatalan booking
 *   yang SUDAH dikonfirmasi, staff yang memutuskan.
 * - Backfill cancel_reason dari catatan lama: sebelum kolom terstruktur ada,
 *   alasan pembatalan ditempel ke notes sebagai "Dibatalkan: <alasan>".
 *   notes TIDAK diubah, hanya disalin ke kolom baru supaya ikut ekspor/laporan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_cancellation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });

        DB::table('bookings')
            ->where('status', 'cancelled')
            ->whereNull('cancel_reason')
            ->whereNotNull('notes')
            ->where('notes', 'like', '%Dibatalkan:%')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $pos = strripos($row->notes, 'Dibatalkan:');
                    if ($pos === false) {
                        continue;
                    }

                    $reason = trim(substr($row->notes, $pos + strlen('Dibatalkan:')));

                    DB::table('bookings')->where('id', $row->id)->update([
                        'cancel_reason'     => $reason !== '' ? $reason : null,
                        'cancelled_by_type' => 'staff',
                        'cancelled_at'      => $row->updated_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_cancellation_requests');
    }
};
