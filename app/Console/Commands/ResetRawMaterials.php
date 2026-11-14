<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kosongkan daftar Bahan Baku untuk diinput ulang (2026-11-13, atas permintaan pemilik karena banyak salah input stok awal). Berbeda
 * dengan inventaris-group:wipe (khusus dev, menghapus seluruh grup Inventaris), perintah ini HANYA menyentuh Bahan Baku dan boleh dijalankan
 * di production, dengan pengaman berlapis:
 *
 *   - Tanpa --confirm hanya MELAPORKAN apa yang akan dihapus (dry-run), tidak mengubah apa pun.
 *   - Dokumen yang menunjuk Bahan Baku (Master Resep, Memo, Permohonan Pembelian, Stock Opname, Penghapusan Stok) tidak ditinggalkan
 *     menggantung: bila ada, penghapusan DITOLAK kecuali --hapus-referensi dipakai. Referensi itu tidak bisa dipertahankan karena Bahan Baku
 *     yang diinput ulang mendapat ID baru, sehingga resep dan dokumen lama tidak akan menemukannya lagi.
 *   - Seluruhnya satu transaksi: gagal di tengah = tidak ada yang berubah.
 *   - Satu baris Histori Aktivitas dicatat berisi jumlah yang dihapus.
 *
 * Yang dihapus: Bahan Baku, batch-nya, SEMUA pergerakan Bahan Baku (riwayat salah input ikut hilang, supaya stok awal yang baru bersih).
 * Tidak disentuh: Barang Habis Pakai, Produk PPF/WF, Aset, Roll film (Scroll Code), Pelanggan, Booking, dan data lain.
 * Penghapusan permanen; satu-satunya jalan kembali adalah memulihkan backup database (backup harian otomatis jam 03:00).
 */
class ResetRawMaterials extends Command
{
    protected $signature = 'bahan-baku:reset {--confirm : Wajib untuk benar-benar menghapus (tanpa ini hanya laporan)} {--hapus-referensi : Ikut hapus dokumen yang menunjuk Bahan Baku (resep, memo, permohonan pembelian, opname, penghapusan stok)}';

    protected $description = 'HAPUS PERMANEN semua Bahan Baku beserta batch dan riwayat pergerakannya, untuk diinput ulang (dry-run tanpa --confirm)';

    /** Tabel dokumen yang menunjuk Bahan Baku: [label, tabel, kolom jenis, kolom id, tabel induk, kolom FK ke induk]. */
    private const REFERENCES = [
        ['Master Resep (baris bahan)', 'film_product_recipe_items', 'item_type', 'item_id', null, null],
        ['Memo Pengambilan/Pengembalian (baris barang)', 'material_memo_items', 'item_type', 'item_id', 'material_memos', 'material_memo_id'],
        ['Permohonan Pembelian', 'purchase_requests', 'item_type', 'item_id', null, null],
        ['Stock Opname (baris barang)', 'stock_opname_items', 'item_type', 'item_id', 'stock_opnames', 'stock_opname_id'],
        ['Penghapusan Stok', 'stock_write_offs', 'writeoffable_type', 'writeoffable_id', null, null],
    ];

    public function handle(): int
    {
        $materials = DB::table('raw_materials')->count();
        $withStock = DB::table('raw_materials')->where('current_stock', '>', 0)->count();
        $batches = DB::table('raw_material_batches')->count();
        $movements = DB::table('raw_material_movements')->count();

        $references = [];
        foreach (self::REFERENCES as [$label, $table, $typeColumn]) {
            $references[$label] = DB::table($table)->where($typeColumn, 'raw_material')->count();
        }
        $referenceTotal = array_sum($references);

        $this->line('');
        $this->info('Yang akan dihapus dari Bahan Baku:');
        $this->table(['Data', 'Jumlah'], [
            ['Bahan Baku', $materials . ($withStock > 0 ? " ({$withStock} di antaranya punya stok)" : '')],
            ['Batch stok (FIFO)', $batches],
            ['Riwayat pergerakan (masuk/keluar/koreksi)', $movements],
        ]);

        $this->info('Dokumen yang menunjuk Bahan Baku:');
        $this->table(['Dokumen', 'Jumlah baris'], collect($references)->map(fn ($n, $label) => [$label, $n])->values());

        if ($materials === 0 && $movements === 0 && $referenceTotal === 0) {
            $this->info('Bahan Baku sudah kosong. Tidak ada yang perlu dihapus.');

            return self::SUCCESS;
        }

        if ($referenceTotal > 0 && ! $this->option('hapus-referensi')) {
            $this->warn("Ada {$referenceTotal} baris dokumen yang menunjuk Bahan Baku. Kalau Bahan Baku dihapus, baris itu menggantung (tidak akan menemukan Bahan Baku yang diinput ulang, ID-nya baru).");
            $this->warn('Tambahkan --hapus-referensi kalau baris itu juga boleh dihapus (Master Resep perlu disusun ulang sesudahnya).');
        }

        if (! $this->option('confirm')) {
            $this->warn('Ini hanya laporan, belum ada yang dihapus. Tambahkan --confirm untuk menghapus.');

            return self::SUCCESS;
        }

        if ($referenceTotal > 0 && ! $this->option('hapus-referensi')) {
            $this->error('Dibatalkan: masih ada dokumen yang menunjuk Bahan Baku dan --hapus-referensi tidak dipakai.');

            return self::FAILURE;
        }

        $deletedParents = ['material_memos' => 0, 'stock_opnames' => 0];

        DB::transaction(function () use (&$deletedParents) {
            if ($this->option('hapus-referensi')) {
                foreach (self::REFERENCES as [, $table, $typeColumn, $idColumn, $parentTable, $parentColumn]) {
                    $parentIds = $parentTable
                        ? DB::table($table)->where($typeColumn, 'raw_material')->pluck($parentColumn)->unique()->all()
                        : [];

                    DB::table($table)->where($typeColumn, 'raw_material')->delete();

                    // Dokumen induk yang barisnya habis karena penghapusan ini ikut dibersihkan (memo atau opname kosong tidak berguna).
                    if ($parentTable && $parentIds !== []) {
                        $itemsTable = $table;
                        $deletedParents[$parentTable] += DB::table($parentTable)
                            ->whereIn('id', $parentIds)
                            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from($itemsTable)->whereColumn("{$itemsTable}.{$parentColumn}", "{$parentTable}.id"))
                            ->delete();
                    }
                }
            }

            DB::table('raw_material_movements')->delete();
            DB::table('raw_material_batches')->delete();
            DB::table('raw_materials')->delete();
        });

        activity('raw_material')
            ->withProperties(['bahan_baku' => $materials, 'batch' => $batches, 'pergerakan' => $movements, 'referensi' => $references, 'dokumen_kosong_dihapus' => $deletedParents])
            ->log("Reset Bahan Baku lewat perintah artisan: {$materials} bahan baku dan {$movements} pergerakan dihapus");

        $this->info('Selesai. Bahan Baku sudah kosong dan siap diinput ulang.');

        return self::SUCCESS;
    }
}
