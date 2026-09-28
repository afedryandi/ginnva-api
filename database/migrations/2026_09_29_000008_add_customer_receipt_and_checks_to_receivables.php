<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Piutang Usaha 2026-09-29 (gap enterprise):
 * - receivables.customer_id (nullable, FK customers): rekap per customer. customer_name tetap
 *   disimpan sebagai snapshot. Backfill dari booking asal.
 * - receivable_payments.receipt_number (nullable, unik): nomor bukti pelunasan. Baris lama
 *   dibiarkan tanpa nomor (tidak dikarang mundur).
 * - Jatuh tempo: piutang dari Booking yang masih berjalan (unpaid/partial) dan belum punya
 *   due_date diberi default 14 hari sejak dicatat, supaya "Terlambat"/aging/pengingat berfungsi.
 *   (Piutang lunas/dibatalkan tidak disentuh.)
 * - CHECK constraint (amount > 0, 0 <= amount_paid <= amount) sebagai lapisan kedua di bawah
 *   validasi service; dilewati (dengan log) bila ada data lama yang melanggar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('customer_name')->constrained('customers')->nullOnDelete();
        });

        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->string('receipt_number')->nullable()->after('receivable_id');
            $table->unique('receipt_number');
        });

        DB::table('receivables')
            ->where('source_type', 'booking')
            ->whereNull('customer_id')
            ->orderBy('id')
            ->get(['id', 'source_id'])
            ->each(function ($row) {
                $customerId = DB::table('bookings')->where('id', $row->source_id)->value('customer_id');

                if ($customerId && DB::table('customers')->where('id', $customerId)->exists()) {
                    DB::table('receivables')->where('id', $row->id)->update(['customer_id' => $customerId]);
                }
            });

        DB::table('receivables')
            ->where('source_type', 'booking')
            ->whereNull('due_date')
            ->whereIn('status', ['unpaid', 'partial'])
            ->orderBy('id')
            ->get(['id', 'created_at'])
            ->each(function ($row) {
                DB::table('receivables')->where('id', $row->id)->update([
                    'due_date' => \Illuminate\Support\Carbon::parse($row->created_at)->addDays(14)->toDateString(),
                ]);
            });

        $violations = DB::table('receivables')
            ->whereRaw('amount <= 0 OR amount_paid < 0 OR amount_paid > amount')
            ->count();

        if ($violations > 0) {
            Log::warning("CHECK constraint receivables dilewati: {$violations} baris lama melanggar aturan nominal.");

            return;
        }

        try {
            DB::statement('ALTER TABLE receivables ADD CONSTRAINT chk_receivables_amounts CHECK (amount > 0 AND amount_paid >= 0 AND amount_paid <= amount)');
        } catch (\Throwable $e) {
            Log::warning('CHECK constraint receivables dilewati: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE receivables DROP CHECK chk_receivables_amounts');
        } catch (\Throwable $e) {
            // constraint tidak ada / driver tidak mendukung -- abaikan
        }

        Schema::table('receivable_payments', function (Blueprint $table) {
            $table->dropUnique(['receipt_number']);
            $table->dropColumn('receipt_number');
        });

        Schema::table('receivables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        // due_date hasil backfill sengaja tidak dikembalikan ke NULL (tidak bisa dibedakan dari input manual).
    }
};
