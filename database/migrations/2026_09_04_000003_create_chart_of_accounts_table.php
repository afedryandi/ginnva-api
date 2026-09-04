<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bagan Akun (Chart of Accounts) — fondasi Fase 2 modul Keuangan, dasar
 * untuk jurnal berpasangan (double-entry) yang akan dibangun di atasnya.
 * Struktur & keputusan desain lengkap ada di dokumen "Bagan Akun Ginnva"
 * (klasifikasi 1000 Aset s.d. 8000 Pajak) yang dibahas sebelum ini.
 *
 * Hierarkis (parent_id self-referencing) — group/header seperti "1100
 * Aset Lancar" cuma pembungkus tampilan/laporan (is_postable=false,
 * TIDAK boleh menerima jurnal langsung), akun detail di bawahnya (mis.
 * "1101 Kas di Tangan") yang benar-benar dipakai transaksi.
 *
 * SATU bagan akun untuk SEMUA toko (bukan diduplikasi per cabang) —
 * dimensi toko tetap lewat store_id di level TRANSAKSI/JURNAL nanti,
 * bukan di tabel ini, konsisten dengan pola store_id yang sudah dipakai
 * di seluruh sistem (Booking, Inventaris, Karyawan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');

            // Klasifikasi 9 kelas (bukan cuma Aset/Kewajiban/Modal/
            // Pendapatan/Beban generik) — HPP dipisah dari Beban
            // Operasional supaya margin kotor bisa dihitung akurat,
            // Pendapatan/Beban Lain-lain & Pajak dipisah dari Beban
            // Operasional supaya Laba Rugi mencerminkan performa
            // operasional murni.
            $table->enum('type', [
                'aset',
                'kewajiban',
                'modal',
                'pendapatan',
                'beban_pokok',
                'beban_operasional',
                'pendapatan_lain',
                'beban_lain',
                'pajak',
            ]);

            // Saldo normal — dipakai validasi & tampilan tanda (+/-) saat
            // jurnal double-entry dibangun di atas tabel ini nanti.
            // Aset/Beban* normal DEBIT; Kewajiban/Modal/Pendapatan*
            // normal KREDIT.
            $table->enum('normal_balance', ['debit', 'kredit']);

            $table->foreignId('parent_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();

            // false untuk akun header/group (mis. "1100 Aset Lancar") —
            // cuma pembungkus tampilan laporan, tidak boleh menerima
            // jurnal langsung. true untuk akun detail yang benar-benar
            // dipakai transaksi.
            $table->boolean('is_postable')->default(true);

            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();

            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
