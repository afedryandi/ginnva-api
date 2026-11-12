<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan Dinas Luar (2026-11-12, keputusan pemilik): karyawan yang bekerja di luar toko (mis. kanvasing) tidak bisa absen karena absen
 * dibatasi radius toko. Mereka mengajukan sehari penuh, atau sebagian hari dengan rentang jam, dengan tujuan yang jelas; boleh diajukan susulan.
 * Sehari penuh: absensi hari itu tercatat sebagai Dinas Luar (entry_type field_duty). Sebagian hari: menit telat dan pulang cepat tidak dihitung
 * pada rentang yang disetujui. Lihat FieldDutyRequestService.
 *
 * Tabel baru saja, tidak mengubah tabel lain: aman dan mudah dibalik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_duty_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // Kosong = sehari penuh. Terisi = dinas sebagian hari pada rentang jam itu (absen masuk dan keluar di toko tetap wajib).
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->text('description');
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'date']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_duty_requests');
    }
};
