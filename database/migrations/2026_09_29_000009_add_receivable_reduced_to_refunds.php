<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Piutang Usaha 2026-09-29: refund atas booking yang masih punya Piutang Usaha
 * dipecah -- bagian yang mengurangi piutang (belum pernah masuk kas) vs bagian kas
 * yang dikembalikan. refunds.receivable_reduced = bagian yang mengurangi piutang
 * (default 0 = refund lama, seluruhnya kas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->decimal('receivable_reduced', 14, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn('receivable_reduced');
        });
    }
};
