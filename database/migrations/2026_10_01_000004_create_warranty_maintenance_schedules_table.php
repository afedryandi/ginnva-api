<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bagian C, "Klaim Garansi & Maintenance PPF Terhubung ke Slot Booking"
 * (2026-10-01) -- SATU baris = SATU occurrence jadwal maintenance (urutan
 * ke-N dari maintenance_quota warranty). Beda dari warranty_maintenance_visits
 * (tetap TIDAK diubah -- ledger kunjungan yang BENAR-BENAR terjadi, ditulis
 * manual staff ATAU otomatis saat booking occurrence ini selesai).
 *
 * Alur: staff set maintenance_interval_months -> baris sequence=1 dibuat
 * (status 'pending') -> H-7 dari scheduled_date, command harian kirim push
 * konfirmasi (status jadi 'confirmation_sent') -> customer confirm (bikin
 * Booking, status 'confirmed') atau decline/diamkan sampai lewat tanggal
 * (status 'forfeited', baris sequence berikutnya otomatis dibuat) -> booking
 * selesai (status 'completed', catat ke warranty_maintenance_visits).
 *
 * Hanya SATU baris per warranty yang berstatus pending/confirmation_sent
 * pada satu waktu (occurrence aktif) -- baris lama forfeited/completed
 * tetap tersimpan sebagai riwayat, tidak pernah ditimpa/dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_maintenance_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warranty_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->date('scheduled_date');
            $table->enum('status', ['pending', 'confirmation_sent', 'confirmed', 'forfeited', 'completed'])->default('pending');
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['warranty_id', 'sequence']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_maintenance_schedules');
    }
};
