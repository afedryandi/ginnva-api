<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tanggal penjualan booking (tanggal nominal transaksinya diproses lewat "Proses Referral"), disimpan di booking sendiri.
 *
 * Sebelumnya tanggal ini hanya ada sebagai tanggal jurnal pendapatan (journal_entries.entry_date), sehingga semua laporan penjualan
 * bergantung pada modul Keuangan. Sejak pembukuan dipegang Accurate 5 (FEATURE_FINANCE mati) tidak ada jurnal lagi, jadi tanggalnya
 * dicatat di sini. Booking yang sudah punya jurnal diisi dari tanggal jurnalnya supaya riwayat laporan tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->date('sale_date')->nullable()->after('journal_entry_id');
            $table->index(['sale_date', 'store_id'], 'bookings_sale_date_store_idx');
        });

        DB::statement('UPDATE bookings b JOIN journal_entries je ON je.id = b.journal_entry_id SET b.sale_date = je.entry_date WHERE b.journal_entry_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_sale_date_store_idx');
            $table->dropColumn('sale_date');
        });
    }
};
