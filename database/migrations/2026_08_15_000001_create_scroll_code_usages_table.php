<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Riwayat tiap kali "Catat Pemakaian" (meter) dijalankan — beda dari
     * scroll_codes.usage_count (cuma angka kumulatif tanpa jejak per
     * kejadian). Dibuat lewat ScrollCode::recordUsage(), read-only sama
     * seperti raw_material_batches/inventory_movements.
     */
    public function up(): void
    {
        Schema::create('scroll_code_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scroll_code_id')->constrained()->cascadeOnDelete();
            $table->decimal('meters', 6, 2);
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['scroll_code_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scroll_code_usages');
    }
};
