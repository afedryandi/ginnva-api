<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Izin pelanggan untuk memakai hasil pekerjaan sebagai materi pemasaran (Marketing/Konten Fase 3). Dicatat saat serah terima, bawaannya
 * TIDAK ada izin (opt-in, keputusan pemilik). Tanpa izin aktif, foto pekerjaan tidak boleh masuk galeri. Izin dapat ditarik kapan pun
 * (UU PDP: persetujuan eksplisit dan hak menarik persetujuan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_consents')) {
            return;
        }

        Schema::create('marketing_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('scope', 30)->default('foto_pekerjaan');
            $table->dateTime('granted_at');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('withdrawn_note', 255)->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_consents');
    }
};
