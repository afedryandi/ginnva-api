<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Audit Persetujuan Pengeluaran 2026-09-28 (gap enterprise):
 * - request_number: nomor pengajuan unik (EXP-YYYYMM-XXXX), di-backfill.
 * - resubmitted_at: penanda pengajuan ditolak/dibatalkan yang SUDAH diajukan
 *   ulang, supaya tidak diajukan berulang kali tanpa jejak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_expense_approvals', function (Blueprint $table) {
            $table->string('request_number', 30)->nullable()->unique()->after('id');
            $table->timestamp('resubmitted_at')->nullable()->after('rejection_note');
        });

        DB::table('finance_expense_approvals')->whereNull('request_number')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                do {
                    $candidate = 'EXP-' . date('Ym', strtotime($row->created_at)) . '-' . Str::upper(Str::random(4));
                } while (DB::table('finance_expense_approvals')->where('request_number', $candidate)->exists());

                DB::table('finance_expense_approvals')->where('id', $row->id)->update(['request_number' => $candidate]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('finance_expense_approvals', function (Blueprint $table) {
            $table->dropUnique(['request_number']);
            $table->dropColumn(['request_number', 'resubmitted_at']);
        });
    }
};
