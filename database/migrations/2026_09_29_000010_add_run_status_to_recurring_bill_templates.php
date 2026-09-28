<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Template Tagihan Rutin 2026-09-29: status eksekusi terakhir supaya template yang
 * macet (akun nonaktif, periode ditutup, nominal di luar batas) terlihat di UI, bukan cuma
 * di log server. last_error dikosongkan otomatis saat generate berikutnya berhasil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_bill_templates', function (Blueprint $table) {
            $table->timestamp('last_run_at')->nullable()->after('next_run_date');
            $table->text('last_error')->nullable()->after('last_run_at');
            $table->timestamp('last_error_at')->nullable()->after('last_error');
            $table->index(['is_active', 'next_run_date']);
        });
    }

    public function down(): void
    {
        Schema::table('recurring_bill_templates', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'next_run_date']);
            $table->dropColumn(['last_run_at', 'last_error', 'last_error_at']);
        });
    }
};
