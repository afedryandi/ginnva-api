<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Izin/Cuti karyawan — pengajuan RENTANG TANGGAL ke depan (beda dari
 * Attendance yang mencatat kejadian 1 hari yang sudah/sedang terjadi).
 * Approval sengaja isFullAccess() (super_admin/direksi), konsisten dengan
 * pola approval lain di sistem ini (Technician, PurchaseRequest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->enum('type', ['izin', 'sakit', 'cuti']);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason');

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
