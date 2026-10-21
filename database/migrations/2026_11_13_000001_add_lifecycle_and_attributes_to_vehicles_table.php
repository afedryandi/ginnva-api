<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Data Fase 1 (2026-10-21): siklus hidup dan atribut kendaraan. Kendaraan lama semuanya aktif. Tipe bodi, rentang tahun, dan
 * catatan opsional (kosong sampai diisi). Aman dijalankan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicles', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('size_category');
                $table->index('is_active');
            }
            if (! Schema::hasColumn('vehicles', 'body_type')) {
                $table->string('body_type', 20)->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('vehicles', 'year_from')) {
                $table->unsignedSmallInteger('year_from')->nullable()->after('body_type');
            }
            if (! Schema::hasColumn('vehicles', 'year_to')) {
                $table->unsignedSmallInteger('year_to')->nullable()->after('year_from');
            }
            if (! Schema::hasColumn('vehicles', 'notes')) {
                $table->string('notes', 255)->nullable()->after('year_to');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            foreach (['notes', 'year_to', 'year_from', 'body_type'] as $column) {
                if (Schema::hasColumn('vehicles', $column)) {
                    $table->dropColumn($column);
                }
            }
            if (Schema::hasColumn('vehicles', 'is_active')) {
                $table->dropIndex(['is_active']);
                $table->dropColumn('is_active');
            }
        });
    }
};
