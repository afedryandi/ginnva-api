<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit alur Booking 2026-10-02 (keputusan user):
 * - Alasan pembatalan terstruktur (cancel_reason/cancelled_by_*/cancelled_at).
 * - pending_reminder_sent_at: penanda reminder SLA booking pending.
 * - booking_reschedule_requests: pengajuan ganti tanggal dari customer
 *   (disetujui/ditolak staff).
 * - booking_messages.read_by_*_at: penanda pesan sudah dibaca per sisi,
 *   untuk badge "pesan belum dibaca". Pesan lama di-backfill sebagai
 *   sudah dibaca supaya badge tidak langsung penuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->text('cancel_reason')->nullable()->after('notes');
            $table->string('cancelled_by_type', 20)->nullable()->after('cancel_reason');
            $table->unsignedBigInteger('cancelled_by_id')->nullable()->after('cancelled_by_type');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by_id');
            $table->timestamp('pending_reminder_sent_at')->nullable()->after('cancelled_at');
            $table->unsignedTinyInteger('pending_reminder_count')->default(0)->after('pending_reminder_sent_at');
        });

        Schema::table('booking_messages', function (Blueprint $table) {
            // Sisi customer: satu orang per booking, cukup satu kolom.
            $table->timestamp('read_by_customer_at')->nullable();
            // Sisi staff: baca dicatat PER STAFF di booking_message_reads
            // (keputusan 2026-10-03). legacy_read = pesan lama sebelum fitur
            // ini, dianggap sudah dibaca semua staff.
            $table->boolean('legacy_read')->default(false);
        });

        DB::table('booking_messages')->update([
            'read_by_customer_at' => DB::raw('created_at'),
            'legacy_read'         => true,
        ]);

        Schema::create('booking_message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();

            $table->unique(['booking_message_id', 'user_id']);
        });

        Schema::create('booking_reschedule_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->date('requested_date');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_message_reads');
        Schema::dropIfExists('booking_reschedule_requests');

        Schema::table('booking_messages', function (Blueprint $table) {
            $table->dropColumn(['read_by_customer_at', 'legacy_read']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['cancel_reason', 'cancelled_by_type', 'cancelled_by_id', 'cancelled_at', 'pending_reminder_sent_at', 'pending_reminder_count']);
        });
    }
};
