<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat klaim after-sales (sesuai mind map "Warranty and after-sales
     * service" > "Warranty and after-sales list" + "Quality Assurance
     * After-Sales Review").
     *
     * Sengaja tabel TERPISAH dari `warranties` (bukan kolom tambahan),
     * karena 1 warranty bisa diajukan klaim berkali-kali dan tiap
     * pengajuan harus tersimpan sebagai riwayat sendiri (tidak overwrite).
     */
    public function up(): void
    {
        Schema::create('warranty_claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_number')->unique(); // CLM-YYYYMM-XXXX

            $table->foreignId('warranty_id')
                ->constrained('warranties')
                ->cascadeOnDelete();

            // Kategori sesuai mind map: "Worry free scraping of car
            // clothes" (anti gores bebas khawatir), "Product Warranty",
            // "Other customizations".
            $table->enum('category', ['worry_free_wrap', 'product_warranty', 'other']);

            $table->text('description')->nullable(); // Keluhan/permintaan customer

            $table->enum('status', ['pending', 'pass', 'reject'])->default('pending');
            $table->text('rejection_reason')->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index('claim_number');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_claims');
    }
};