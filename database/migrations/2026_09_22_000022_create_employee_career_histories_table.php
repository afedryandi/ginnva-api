<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Perpindahan Karyawan (wizard before→after) + Riwayat Karir" (audit
 * Majoo, f57), dibangun 2026-09-22. SENGAJA dibatasi ke perpindahan
 * TOKO/OUTLET saja (bukan "jabatan"/"departemen" -- Ginnva belum punya
 * entitas itu secara formal, lihat temuan terpisah f79/f80 yang belum
 * diputuskan) -- perpindahan outlet adalah satu-satunya dimensi
 * "karir" yang sudah punya data solid untuk dicatat sekarang.
 *
 * Dicatat OTOMATIS tiap kali User.store_id berubah (lihat
 * User::booted()), BUKAN cuma lewat aksi tertentu -- supaya cakupannya
 * tuntas tidak peduli lewat jalur mana perubahannya (form edit biasa,
 * bulk action, dll), beda dari LogsActivity generik yang formatnya
 * mentah kolom-per-kolom, bukan "riwayat karir" yang mudah dibaca HR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_career_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('previous_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('new_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_career_histories');
    }
};
