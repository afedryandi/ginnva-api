<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan Izin Sebagian Hari (2026-11-18, keputusan pemilik): izin singkat yang tidak memotong jatah cuti maupun gaji, dalam tiga kategori:
 * Terlambat Masuk Kerja, Keluar Lingkungan Kantor, dan Pulang Cepat. Jam yang disetujui menghapus menit telat atau pulang cepat yang tercakup
 * dari rekap absensi (lihat Attendance::partialLeaveCoveredMinutes). Aturan ada di PartialLeaveRequestService.
 *
 * start_time dan end_time selalu terisi: Terlambat Masuk Kerja memakai 00:00 sampai jam tiba, Pulang Cepat memakai jam pulang sampai 23:59,
 * Keluar Lingkungan Kantor memakai jam keluar sampai jam kembali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partial_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('category', 30);
            $table->time('start_time');
            $table->time('end_time');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'date']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partial_leave_requests');
    }
};
