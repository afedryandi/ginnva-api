<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\ConsumableItem;
use App\Models\InventoryItem;
use App\Models\MaterialMemo;
use App\Models\PurchaseRequest;
use App\Models\RawMaterial;
use Illuminate\Console\Command;

/**
 * Kosongkan SEMUA data di nav group "Inventaris": Produk PPF/WF (+Riwayat
 * Keluar/Masuk), Bahan Baku (+Riwayat Bahan Baku), Aset Tetap, Barang
 * Habis Pakai (+Riwayat Barang Habis Pakai), Memo Pengambilan/
 * Pengembalian, Permohonan Pembelian.
 *
 * PERINGATAN: ini BUKAN command demo/cleanup seperti *-group:demo — ini
 * menghapus data ASLI (bukan cuma baris bertanda "Demo -"), permanen,
 * tanpa cara mengembalikan selain restore backup DB. Dibuat khusus atas
 * permintaan eksplisit untuk mengosongkan data di ginnva-api-dev
 * (sistem development, BUKAN production) — --confirm wajib disebutkan
 * supaya tidak ke-trigger tidak sengaja.
 *
 * Cukup hapus baris di 6 tabel INDUK (InventoryItem, RawMaterial,
 * ConsumableItem, Asset, MaterialMemo, PurchaseRequest) — semua tabel
 * riwayat/anak (inventory_movements, raw_material_batches,
 * raw_material_movements, consumable_item_movements, material_memo_items)
 * cascadeOnDelete ke tabel induknya masing-masing (lihat migrations
 * terkait), jadi ikut terhapus otomatis tanpa perlu query terpisah.
 * PurchaseRequest tidak punya tabel anak.
 *
 * TIDAK menyentuh FilmProduct/Vehicle/Store/User atau tabel lain di luar
 * grup Inventaris — model-model di command ini semuanya independen,
 * tidak ada FK yang mengarah KELUAR grup ini dari sisi lain (aman dari
 * efek samping ke Marketing/Booking/Karyawan).
 */
class WipeInventarisGroup extends Command
{
    protected $signature = 'inventaris-group:wipe {--confirm : Wajib disebutkan untuk benar-benar menghapus}';

    protected $description = 'HAPUS PERMANEN semua data nav group Inventaris (bukan cuma data demo) — hanya untuk dev/testing';

    public function handle(): int
    {
        if (! $this->option('confirm')) {
            $this->error('Command ini menghapus PERMANEN semua data di grup Inventaris (Produk PPF/WF, Bahan Baku, Aset Tetap, Barang Habis Pakai, Memo, Permohonan Pembelian) beserta seluruh riwayatnya.');
            $this->warn('Jalankan lagi dengan --confirm kalau memang yakin: php artisan inventaris-group:wipe --confirm');
            return self::FAILURE;
        }

        $counts = [
            'Produk PPF/WF (+ riwayat keluar/masuk)' => InventoryItem::query()->count(),
            'Bahan Baku (+ riwayat & batch)' => RawMaterial::query()->count(),
            'Barang Habis Pakai (+ riwayat)' => ConsumableItem::query()->count(),
            'Aset Tetap' => Asset::query()->count(),
            'Memo Pengambilan/Pengembalian (+ item)' => MaterialMemo::query()->count(),
            'Permohonan Pembelian' => PurchaseRequest::query()->count(),
        ];

        InventoryItem::query()->delete();
        RawMaterial::query()->delete();
        ConsumableItem::query()->delete();
        Asset::query()->delete();
        MaterialMemo::query()->delete();
        PurchaseRequest::query()->delete();

        $this->info('Semua data nav group Inventaris sudah dihapus permanen:');
        $this->table(['Tabel Induk', 'Jumlah Baris Dihapus'], collect($counts)->map(fn ($n, $label) => [$label, $n])->values());

        return self::SUCCESS;
    }
}
