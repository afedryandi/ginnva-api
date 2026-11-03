<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat Bahan Baku (audit 2026-11-03):
 * - source_type/source_id: dokumen asal pergerakan (Memo, penerimaan Permohonan Pembelian, Stok Terbuang), supaya pembatalan dari
 *   riwayat tidak membuat stok dan dokumen sumbernya tidak sinkron. Pergerakan lama tidak punya penanda (tidak ditebak).
 * - reversed_*: pembatalan menyimpan baris aslinya (sebelumnya dihapus) dengan alasan wajib.
 * - raw_material_batches.is_restoration: batch hasil pembatalan Keluar/opname turun, supaya total batch tetap sama dengan stok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->string('source_type', 40)->nullable()->after('note');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->timestamp('reversed_at')->nullable()->after('user_id');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->string('reversed_reason', 500)->nullable()->after('reversed_by');
            $table->index(['source_type', 'source_id'], 'rmm_source_idx');
            $table->index(['store_id', 'created_at'], 'rmm_store_created_idx');
        });

        Schema::table('consumable_item_movements', function (Blueprint $table) {
            $table->string('source_type', 40)->nullable()->after('note');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->index(['source_type', 'source_id'], 'cim_source_idx');
        });

        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->boolean('is_restoration')->default(false)->after('is_adjustment');
        });
    }

    public function down(): void
    {
        Schema::table('raw_material_batches', function (Blueprint $table) {
            $table->dropColumn('is_restoration');
        });

        Schema::table('consumable_item_movements', function (Blueprint $table) {
            $table->dropIndex('cim_source_idx');
            $table->dropColumn(['source_type', 'source_id']);
        });

        Schema::table('raw_material_movements', function (Blueprint $table) {
            $table->dropIndex('rmm_source_idx');
            $table->dropIndex('rmm_store_created_idx');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['source_type', 'source_id', 'reversed_at', 'reversed_reason']);
        });
    }
};