<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom `channel` semula enum('email','whatsapp') — tapi Staff\AuthController
 * memakai channel 'staff_reset_password' (beda dari OTP login customer biasa,
 * supaya kode reset password staff tidak bisa dipakai untuk login customer
 * atau sebaliknya). Nilai itu tidak ada di enum, jadi MySQL men-truncate/
 * menolak insert-nya ("Data truncated for column 'channel'"). Diganti ke
 * string biasa supaya channel baru di masa depan tidak perlu migrasi lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('channel', 30)->default('email')->change();
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->enum('channel', ['email', 'whatsapp'])->default('email')->change();
        });
    }
};
