<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan Lembur (2026-11-12, keputusan pemilik): karyawan tidak boleh lembur seenaknya. Lembur wajib diajukan SEBELUM dimulai, dengan pekerjaan
 * yang jelas (tautan Booking atau uraian tertulis), lalu disetujui store manager toko itu atau akses penuh. Hanya jam yang disetujui yang diakui
 * sebagai lembur; upahnya tetap dihitung di luar sistem. Lihat OvertimeRequestService untuk aturan batas jam dan persetujuan.
 *
 * Tabel baru saja, tidak mengubah tabel lain: aman dan mudah dibalik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('minutes');
            $table->text('description')->nullable();
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
        Schema::dropIfExists('overtime_requests');
    }
};
