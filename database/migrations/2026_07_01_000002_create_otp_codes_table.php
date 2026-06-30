<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kode OTP untuk login/register customer. Tabel generik (bukan
     * khusus email) — kolom `channel` ('email' / 'whatsapp') disiapkan
     * dari awal supaya saat WhatsApp OTP ditambah belakangan, cukup
     * tambah 1 service baru tanpa migrasi skema ulang.
     *
     * `identifier` menyimpan email ATAU nomor HP yang dituju (tergantung
     * channel), disengaja sebagai 1 kolom generik daripada 2 kolom
     * terpisah supaya query verifikasi tetap sederhana.
     */
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('identifier'); // email atau nomor HP
            $table->enum('channel', ['email', 'whatsapp'])->default('email');
            $table->string('code', 6);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['identifier', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
