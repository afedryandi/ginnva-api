<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permohonan Pembelian/Stok — staff ajukan restock Bahan Baku/Barang Habis
 * Pakai (item_id merujuk baris yang sudah ada) atau Aset baru (item_id
 * kosong, item_name diisi manual karena barangnya belum ada di katalog),
 * lalu super_admin/direksi approve/reject. Approval TIDAK otomatis bikin
 * movement stok — staff tetap catat stok masuk lewat alur "Catat Stok"
 * yang sudah ada setelah barang benar-benar sampai, baru tandai permohonan
 * ini "Terpenuhi". item_type/item_id sengaja string manual (bukan morphTo
 * Eloquent), mengikuti pola yang sudah dipakai MaterialMemoItem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->enum('item_type', ['raw_material', 'consumable_item', 'asset']);
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->string('unit')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->text('reason')->nullable();

            $table->enum('status', ['pending', 'approved', 'rejected', 'fulfilled'])->default('pending');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('fulfilled_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};
