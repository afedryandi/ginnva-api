<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Stok Terbuang" (write-off barang rusak/kedaluwarsa/hilang) — diminta
 * 2026-09-10, analog menu Majoo "Kelola Stok > Stok Terbuang".
 *
 * Beda dari "Sesuaikan Stok (Opname)" yang generik: write-off punya
 * ALASAN terstruktur + posting jurnal KERUGIAN otomatis (Debit 6520
 * Beban Kerugian Persediaan, Kredit 1130/1131 akun persediaan) senilai
 * qty x unit_cost. Kalau unit_cost belum diisi, stok tetap dikurangi
 * tapi jurnal dilewati (tidak bisa dinilai — ditandai di UI).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Akun 6520 — Beban Kerugian Persediaan (anak 6500 Beban Lain-lain).
        if (! DB::table('chart_of_accounts')->where('code', '6520')->exists()) {
            $parentId = DB::table('chart_of_accounts')->where('code', '6500')->value('id');

            DB::table('chart_of_accounts')->insert([
                'code' => '6520',
                'name' => 'Beban Kerugian Persediaan',
                'type' => 'beban_operasional',
                'normal_balance' => 'debit',
                'parent_id' => $parentId,
                'is_postable' => true,
                'is_active' => true,
                'is_cash' => false,
                'cash_flow_category' => 'operasional',
                'description' => 'Stok bahan/consumable yang di-write-off karena rusak, kedaluwarsa, atau hilang (StockWriteOff).',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('stock_write_offs', function (Blueprint $table) {
            $table->id();
            // Pola manual-morph sama dgn MaterialMemoItem.item_type.
            $table->enum('writeoffable_type', ['raw_material', 'consumable_item']);
            $table->unsignedBigInteger('writeoffable_id');
            $table->string('item_name');
            $table->string('unit')->nullable();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('total_value', 14, 2)->nullable();
            $table->enum('reason', ['damaged', 'expired', 'lost', 'other']);
            $table->string('note')->nullable();
            // Nullable — dipakai nanti kalau stok sudah per-cabang.
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['writeoffable_type', 'writeoffable_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_write_offs');
        DB::table('chart_of_accounts')->where('code', '6520')->delete();
    }
};
