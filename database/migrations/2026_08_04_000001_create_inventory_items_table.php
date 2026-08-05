<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu baris = SATU unit fisik barang (kardus/gulungan) yang dapat 1
     * kode unik + 1 QR code sendiri untuk ditempel & di-scan — BUKAN
     * katalog per jenis barang. Kalau ada 10 kardus produk yang sama, itu
     * 10 baris terpisah dengan kode berbeda-beda.
     *
     * TIDAK ADA field kuantitas sama sekali — 1 kardus/kemasan SELALU
     * berisi tepat 1 unit/gulungan (bukan kardus isi banyak barang), jadi
     * status cukup 2 kemungkinan: ada di gudang (in_stock) atau sudah
     * keluar (out). Kalau nanti ternyata ada barang yang dikemas beda
     * (isi >1 per kardus), itu didaftarkan sebagai baris terpisah per
     * unit juga (1 kardus asli isi 5 → daftarkan 5 baris/5 QR berbeda),
     * bukan lewat field jumlah.
     */
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // dikodekan ke QR, mis. INV-A1B2C3D4
            $table->string('name');
            $table->string('category')->nullable();

            $table->enum('status', ['in_stock', 'out'])->default('in_stock');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
