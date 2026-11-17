<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memo barang berpersetujuan (2026-11-17): installer mengajukan "mau ambil barang" (tanpa daftar dan jumlah, karena jumlahnya belum
 * tentu), store manager menyetujui, staff gudang mencatat barang dan jumlah yang benar-benar diambil lalu menekan "Selesai diserahkan"
 * (memo terkunci), direksi diberi tahu. Memo yang sudah ada tetap alur langsung ('direct').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_memos', function (Blueprint $table) {
            $table->string('flow', 20)->default('direct')->after('store_id');
            $table->string('request_status', 20)->nullable()->after('flow');
            // Keperluan pengajuan terakhir (mis. alasan minta tambahan).
            $table->string('request_note', 500)->nullable();
            // Kendaraan terstruktur (sama penamaan dengan bookings); vehicle_info tetap ada sebagai teks gabungan untuk tampilan dan pencarian.
            $table->string('vehicle_brand', 60)->nullable();
            $table->string('vehicle_model', 60)->nullable();
            $table->string('vehicle_plate', 20)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('fulfilled_at')->nullable();

            $table->index(['request_status', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::table('material_memos', function (Blueprint $table) {
            $table->dropIndex(['request_status', 'store_id']);
            foreach (['approved_by', 'rejected_by', 'fulfilled_by'] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }
            $table->dropColumn(['flow', 'request_status', 'request_note', 'vehicle_brand', 'vehicle_model', 'vehicle_plate', 'submitted_at', 'approved_at', 'rejected_at', 'reject_reason', 'fulfilled_at']);
        });
    }
};
