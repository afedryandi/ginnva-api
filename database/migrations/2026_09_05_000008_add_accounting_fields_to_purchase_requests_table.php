<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * purchase_requests SEBELUMNYA tidak punya kolom nominal Rupiah sama
 * sekali (cuma quantity+unit, murni permintaan barang) — tidak ada
 * dasar data untuk membuat jurnal keuangan tanpa ini. actual_cost
 * diisi kasir/admin SAAT menandai "Terpenuhi" (lihat form aksi
 * 'fulfill' di PurchaseRequestResource), BUKAN saat permohonan
 * diajukan — karena harga aktual baru pasti setelah barang benar-benar
 * dibeli, bisa beda dari estimasi awal.
 *
 * journal_entry_id — link ke jurnal Persediaan/Aset yang otomatis
 * dibuat, lihat PurchaseRequestPostingService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->decimal('actual_cost', 14, 2)->nullable()->after('quantity');
            $table->foreignId('journal_entry_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn('actual_cost');
        });
    }
};
