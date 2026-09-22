<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Alur approval utk absensi anomali" (audit Majoo, f24), dibangun
 * 2026-09-22 atas keputusan user: staff non-manager WAJIB ajukan
 * permintaan koreksi (alasan tertulis) sebelum data Attendance resmi
 * berubah -- store_manager/full-access approve/reject dgn catatan.
 * store_manager/full-access SENDIRI tetap bisa edit langsung lewat
 * AttendanceResource (self-approval tidak menambah kontrol, sama
 * filosofi TransactionApprovalService).
 *
 * `attendance_id` NULL = permintaan ENTRI BARU (mis. lupa absen sama
 * sekali) -- bukan koreksi baris yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_correction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('entry_type');
            $table->dateTime('clock_in_at')->nullable();
            $table->dateTime('clock_out_at')->nullable();
            $table->string('reason');
            $table->string('status')->default('pending');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_correction_requests');
    }
};
