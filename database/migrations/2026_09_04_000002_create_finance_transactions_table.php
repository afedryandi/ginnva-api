<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transaksi Keuangan — 1 baris = 1 kejadian pemasukan ATAU pengeluaran,
 * per-toko (store_id, konsisten dengan Booking/Inventaris/Karyawan yang
 * semua di-scope per-toko — staff toko cuma catat/lihat transaksi
 * tokonya sendiri, full-access bisa lihat & filter semua toko).
 *
 * SENGAJA BUKAN double-entry bookkeeping — tidak ada Jurnal/Neraca/
 * akun debit-kredit, cuma tabel transaksi datar (amount + type +
 * kategori) yang dijumlahkan untuk laporan bulanan. Bisa di-upgrade ke
 * sistem akuntansi penuh nanti kalau memang dibutuhkan (lihat memory
 * keputusan awal modul ini).
 *
 * type DISALIN dari finance_categories.type saat transaksi dibuat
 * (bukan cuma dibaca lewat relasi) — supaya laporan tetap akurat kalau
 * kategori suatu saat dinonaktifkan, dan supaya filter/index bisa
 * langsung ke kolom ini tanpa join tambahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['in', 'out']);
            $table->foreignId('finance_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('transaction_date');
            $table->text('description')->nullable();
            $table->string('receipt')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'transaction_date']);
            $table->index(['type', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_transactions');
    }
};
