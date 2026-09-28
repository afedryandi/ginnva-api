<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Audit Persetujuan Transaksi 2026-09-29 (gap enterprise, setara pola
 * Persetujuan Pengeluaran):
 * - request_number APR-YYYYMM-XXXX (unik, di-backfill).
 * - store_id (dari booking, di-backfill): scoping store manager tanpa join.
 * - resubmitted_at: pengajuan ditolak/dibatalkan yang sudah diajukan ulang.
 * - status: tambah 'cancelled' (pengaju membatalkan pengajuan yang menunggu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_approval_requests', function (Blueprint $table) {
            $table->string('request_number', 30)->nullable()->unique()->after('id');
            $table->foreignId('store_id')->nullable()->after('booking_id')->constrained('stores')->nullOnDelete();
            $table->timestamp('resubmitted_at')->nullable()->after('decision_note');
        });

        DB::statement("ALTER TABLE transaction_approval_requests MODIFY status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending'");

        DB::statement('
            UPDATE transaction_approval_requests
            INNER JOIN bookings ON bookings.id = transaction_approval_requests.booking_id
            SET transaction_approval_requests.store_id = bookings.store_id
            WHERE transaction_approval_requests.store_id IS NULL
        ');

        DB::table('transaction_approval_requests')->whereNull('request_number')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                do {
                    $candidate = 'APR-' . date('Ym', strtotime($row->created_at)) . '-' . Str::upper(Str::random(4));
                } while (DB::table('transaction_approval_requests')->where('request_number', $candidate)->exists());

                DB::table('transaction_approval_requests')->where('id', $row->id)->update(['request_number' => $candidate]);
            }
        });
    }

    public function down(): void
    {
        DB::table('transaction_approval_requests')->where('status', 'cancelled')->update(['status' => 'rejected']);
        DB::statement("ALTER TABLE transaction_approval_requests MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");

        Schema::table('transaction_approval_requests', function (Blueprint $table) {
            $table->dropUnique(['request_number']);
            $table->dropConstrainedForeignId('store_id');
            $table->dropColumn(['request_number', 'resubmitted_at']);
        });
    }
};
