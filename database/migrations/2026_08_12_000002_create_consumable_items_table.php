<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Barang Habis Pakai — perlengkapan operasional yang terpakai habis
     * (lakban, lap, cutter, isi cutter, dll), BEDA dari Aset Tetap (kursi,
     * AC, laptop — individual, biasanya dipasang QR, tidak "habis") dan
     * dari Bahan Baku (khusus material produksi PPF/WF). Strukturnya
     * SAMA PERSIS dengan raw_materials (dilacak per kuantitas) karena
     * memang kelakuannya identik — cuma tabel terpisah supaya daftar
     * "material produksi" tidak campur sama "perlengkapan operasional".
     */
    public function up(): void
    {
        Schema::create('consumable_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->nullable()->unique();
            $table->string('category')->nullable();
            $table->string('unit', 20); // satuan: pcs, roll, box, dll — teks bebas

            $table->decimal('current_stock', 12, 2)->default(0);
            $table->decimal('reorder_point', 12, 2)->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumable_items');
    }
};
