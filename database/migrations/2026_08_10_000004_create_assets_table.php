<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aset tetap perusahaan (laptop, mesin cutting, kendaraan operasional,
     * dll) — beda dari inventory_items (barang dagangan yang keluar-masuk
     * gudang) dan raw_materials (bahan baku yang habis terpakai). Aset di
     * sini TIDAK "habis" — cuma berpindah tangan/lokasi atau berubah
     * kondisi, jadi TIDAK perlu tabel movement dengan quantity. Riwayat
     * perubahannya cukup lewat activity log (LogsActivity, sama seperti
     * Customer/Partner/Store), bukan tabel log khusus.
     */
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_tag', 20)->unique(); // mis. ASSET-A1B2C3D4
            $table->string('name');
            $table->string('category')->nullable(); // mis. Elektronik, Kendaraan, Mesin, Furnitur

            $table->enum('status', ['aktif', 'diperbaiki', 'rusak', 'dijual', 'hilang'])->default('aktif');

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();

            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 12, 2)->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
