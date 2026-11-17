<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPK manual (keputusan 2026-11-17): SPK boleh dibuat tanpa menunggu booking. booking_id jadi boleh kosong (indeks unik tetap:
 * MySQL mengizinkan banyak NULL, jadi "satu booking satu SPK" tetap terjaga), dan SPK manual dapat ditautkan ke booking belakangan.
 * Installer yang mengerjakan SPK manual disimpan di spk_installers (SPK berbooking tetap memakai installer booking-nya).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spks', function (Blueprint $table) {
            $table->unsignedBigInteger('booking_id')->nullable()->change();
        });

        Schema::create('spk_installers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spk_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['spk_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spk_installers');

        // Tidak dikembalikan ke wajib-isi: SPK manual yang sudah ada tidak punya booking, sehingga mengembalikan NOT NULL akan gagal.
    }
};
