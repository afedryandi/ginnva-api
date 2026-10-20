<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keuangan Fase 0 (2026-11-04): transaksi keuangan yang "dihapus" tidak lagi dihapus fisik. Buku dan dokumen dasar pembukuan wajib
 * disimpan 10 tahun (UU KUP Pasal 28 ayat 11, menurut ringkasan sumber sekunder; perlu dikonfirmasi konsultan pajak). Jurnal pembalik dan
 * alasan penghapusan sudah dicatat sejak sebelumnya; kini barisnya sendiri ikut tersimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
