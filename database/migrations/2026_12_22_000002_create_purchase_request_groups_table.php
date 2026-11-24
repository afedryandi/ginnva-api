<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permohonan Pembelian banyak barang (2026-11-23): satu nomor induk (PP-yyyymm-NNNN) memuat beberapa baris. Setiap baris tetap satu
     * baris purchase_requests dengan nomor PR sendiri, jadi persetujuan, penerimaan, hutang, dan jurnal yang sudah ada tetap bekerja per
     * baris. Permohonan lama (satu barang) dibiarkan tanpa induk (kolom null). Murni tambahan, aman dibalik.
     */
    public function up(): void
    {
        Schema::create('purchase_request_groups', function (Blueprint $table) {
            $table->id();
            $table->string('group_number', 32)->unique();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'created_at']);
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->foreignId('purchase_request_group_id')->nullable()->constrained('purchase_request_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_request_group_id');
        });

        Schema::dropIfExists('purchase_request_groups');
    }
};
