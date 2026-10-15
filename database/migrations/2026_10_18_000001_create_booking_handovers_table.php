<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catatan serah terima unit (Fase 3B pengerjaan ulang booking, 2026-10-18). Satu baris per booking. Tanda tangan serah terima
 * dilakukan BASAH di kertas (keputusan pemilik), jadi sistem hanya mencatat bahwa tanda tangan basah sudah diterima, bukan
 * menyimpan tanda tangan digital.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_handovers')) {
            return;
        }

        Schema::create('booking_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('care_instructions_given')->default(false);
            $table->boolean('wet_signature_required')->default(false);
            $table->boolean('wet_signature_received')->default(false);
            $table->string('final_photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('handed_over_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_handovers');
    }
};
