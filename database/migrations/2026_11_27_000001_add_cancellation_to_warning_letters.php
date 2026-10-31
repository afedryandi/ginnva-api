<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembatalan Surat Peringatan (audit Surat Peringatan, 2026-10-31). SP yang sudah dibaca karyawan tidak boleh diubah atau dihapus; bila
 * ternyata keliru, surat DIBATALKAN (tetap tersimpan sebagai bukti, tidak dihitung masa berlaku maupun saran tingkat berikutnya) dengan
 * alasan dan pelaku tercatat. Hanya menambah kolom bernilai kosong, aman dijalankan di data yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warning_letters', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('acknowledged_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('warning_letters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
