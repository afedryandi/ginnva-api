<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perubahan harga terjadwal (audit ACCURATE 5, "Penyesuaian Harga Jual" dengan tanggal efektif): kenaikan atau penurunan harga
     * produk film dalam persen yang berlaku otomatis pada tanggal tertentu. Harga lama dan baru tetap tercatat di riwayat aktivitas
     * tiap harga yang berubah.
     */
    public function up(): void
    {
        Schema::create('scheduled_price_changes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->date('effective_date');
            $table->decimal('percent', 6, 2);
            // 0 = tanpa pembulatan; selain itu pembulatan ke kelipatan terdekat (mis. 1000).
            $table->unsignedInteger('rounding_step')->default(0);
            // Kosong = semua produk film yang aktif.
            $table->json('product_ids')->nullable();
            $table->boolean('include_group_prices')->default(false);
            $table->string('status', 12)->default('scheduled');
            $table->timestamp('applied_at')->nullable();
            $table->unsignedInteger('affected_prices')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_price_changes');
    }
};
