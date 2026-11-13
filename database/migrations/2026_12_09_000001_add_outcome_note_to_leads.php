<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan bebas untuk alasan "Lainnya" pada hasil Tidak Jadi (2026-11-13), di tiga tipe lead. Nullable: lead lama tetap valid.
 */
return new class extends Migration
{
    private const TABLES = ['quotations', 'product_inquiries', 'partnership_inquiries'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'outcome_note')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('outcome_note', 255)->nullable()->after('outcome_reason'));
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'outcome_note')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('outcome_note'));
            }
        }
    }
};
