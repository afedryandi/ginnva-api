<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap DIPERBAIKI 2026-09-26 (audit Riwayat Poin Partner) -- sama gap
 * yang sudah ditutup untuk point_transactions (customer, migrasi
 * 2026_09_26_000003) tapi ketinggalan untuk partner_point_transactions.
 * Nullable & hanya diisi untuk entri manual (reference_type='manual').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_point_transactions', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('reference_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('partner_point_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
