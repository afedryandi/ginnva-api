<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Kebijakan (2026-11-23): lampiran (surat dokter) wajib untuk SEMUA pengajuan Sakit, termasuk satu hari. Sebelumnya wajib mulai 2 hari.
     * Hanya mengubah jenis bawaan "sakit" yang masih belum mewajibkan dari hari pertama; angka bisa diubah lagi di menu Jenis Izin & Cuti.
     */
    public function up(): void
    {
        DB::table('leave_types')
            ->where('code', 'sakit')
            ->where(fn ($q) => $q->whereNull('document_required_from_days')->orWhere('document_required_from_days', '>', 1))
            ->update(['document_required_from_days' => 1]);
    }

    public function down(): void
    {
        DB::table('leave_types')->where('code', 'sakit')->where('document_required_from_days', 1)->update(['document_required_from_days' => 2]);
    }
};
