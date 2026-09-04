<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\ConsumableItem;
use App\Models\InventoryItem;
use App\Models\MaterialMemo;
use App\Models\MaterialMemoItem;
use App\Models\PurchaseRequest;
use App\Models\RawMaterial;
use App\Models\Store;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Demo/testing manual untuk nav group "Inventaris": Produk PPF/WF
 * (+Riwayat Keluar/Masuk), Bahan Baku (+Riwayat Bahan Baku), Aset Tetap,
 * Barang Habis Pakai (+Riwayat Barang Habis Pakai), Memo Pengambilan/
 * Pengembalian, Permohonan Pembelian. ("Dashboard Inventaris" tidak
 * punya data sendiri — cuma agregat dari fitur-fitur ini.)
 *
 * Prinsip sama seperti *-group:demo sebelumnya — pakai ulang SERVICE
 * METHOD ASLI (InventoryItem::recordMovement(), RawMaterial::
 * recordMovement(), ConsumableItem::recordMovement(), Asset::
 * generateAssetTag(), MaterialMemo::generateMemoNumber()) supaya
 * status/stok/batch/riwayat tetap konsisten dengan alur produksi
 * sungguhan, bukan insert mentah yang bisa bikin data tidak sinkron.
 *
 * Tidak ada Observer terdaftar untuk model apa pun di grup ini (lihat
 * AppServiceProvider::boot()) — tidak perlu withoutEvents() sama sekali.
 *
 * User & Store yang dipakai untuk kolom atribusi (created_by, user_id,
 * requested_by, dst) adalah user/store ASLI yang sudah ada (bukan bikin
 * akun baru) — beda dari karyawan-group:demo, field-field ini di sini
 * cuma metadata "siapa yang input/toko mana" (tidak nempel ke riwayat
 * personal karyawan seperti absensi/gaji/SP), jadi aman dipakai ulang.
 *
 * Semua baris demo ditandai lewat marker "Demo - " di kolom nama/reason/
 * notes masing-masing, supaya gampang dibersihkan lagi lewat --cleanup.
 */
class DemoInventarisGroup extends Command
{
    protected $signature = 'inventaris-group:demo
        {--cleanup : Hapus semua data demo grup ini, bukan generate}';

    protected $description = 'Bikin data dummy untuk nav group Inventaris (demo/testing UI)';

    private const MARK_PREFIX = 'Demo - ';

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        $store = Store::where('is_active', true)->first();
        $user = User::where('is_active', true)->inRandomOrder()->first();

        if (! $store || ! $user) {
            $this->error('Butuh minimal 1 toko aktif dan 1 user aktif untuk atribusi data dummy — pastikan keduanya ada dulu.');
            return self::FAILURE;
        }

        $inventoryItems = $this->createInventoryItems($store, $user);
        $rawMaterials = $this->createRawMaterials($user);
        $consumableItems = $this->createConsumableItems($user);
        $this->createAssets($store, $user);
        $this->createMaterialMemos($store, $user, $rawMaterials, $consumableItems);
        $this->createPurchaseRequests($store, $user, $rawMaterials, $consumableItems);

        $this->newLine();
        $this->info('Data dummy grup Inventaris selesai dibuat. Cek Filament: Inventaris.');
        $this->info('Kalau sudah selesai lihat-lihat, bersihkan datanya dengan: php artisan inventaris-group:demo --cleanup');

        return self::SUCCESS;
    }

    /**
     * InventoryItem::recordMovement() dipakai ulang persis — status
     * in_stock/out & riwayat (InventoryMovement) ikut konsisten seperti
     * staff scan barang sungguhan lewat mobile app. scroll_code_id
     * SENGAJA dikosongkan (null) — di luar scope grup Inventaris, tidak
     * perlu dilibatkan supaya data demo ini berdiri sendiri.
     */
    private function createInventoryItems(Store $store, User $user): \Illuminate\Support\Collection
    {
        $items = collect([
            ['name' => 'PPF Bening 1.52m x 15m', 'category' => 'PPF'],
            ['name' => 'PPF Matte 1.52m x 15m', 'category' => 'PPF'],
            ['name' => 'Window Film Ceramic 70%', 'category' => 'Window Film'],
            ['name' => 'Window Film Carbon 40%', 'category' => 'Window Film'],
        ])->map(function (array $data) use ($user) {
            $item = InventoryItem::create([
                'code' => 'INV-DEMO' . Str::upper(Str::random(6)),
                'name' => self::MARK_PREFIX . $data['name'],
                'category' => $data['category'],
                'received_date' => Carbon::now()->subDays(rand(5, 30))->toDateString(),
                'notes' => 'Data dummy untuk demo/testing.',
                'created_by' => $user->id,
            ]);

            return $item;
        });

        // 2 barang pertama "dikeluarkan" ke toko, 1 di antaranya lalu
        // "dikembalikan" — supaya Riwayat Keluar/Masuk ada variasi jenis
        // kejadian (bukan cuma "masuk" doang).
        $items->take(2)->each(fn (InventoryItem $item) => $item->recordMovement('out', $user->id, self::MARK_PREFIX . 'Dikirim ke toko untuk demo/testing.', $store->id));
        $items->first()->recordMovement('in', $user->id, self::MARK_PREFIX . 'Dikembalikan ke gudang pusat (demo/testing).');

        $this->info("{$items->count()} produk PPF/WF dummy dibuat (beserta riwayat keluar/masuk).");

        return $items;
    }

    /**
     * RawMaterial::recordMovement() dipakai ulang persis — batch (FIFO)
     * & current_stock ikut konsisten seperti staff catat stok sungguhan.
     */
    private function createRawMaterials(User $user): \Illuminate\Support\Collection
    {
        $materials = collect([
            ['name' => 'Adhesive Optically Clear', 'unit' => 'liter', 'qty' => 20, 'cost' => 350000],
            ['name' => 'Backing Paper', 'unit' => 'roll', 'qty' => 15, 'cost' => 120000],
            ['name' => 'Primer Kaca Film', 'unit' => 'liter', 'qty' => 10, 'cost' => 200000],
        ])->map(function (array $data) use ($user) {
            $material = RawMaterial::create([
                'name' => self::MARK_PREFIX . $data['name'],
                'code' => 'RM-DEMO' . Str::upper(Str::random(6)),
                'category' => 'Bahan Baku Demo',
                'unit' => $data['unit'],
                'reorder_point' => round($data['qty'] * 0.2, 2),
                'notes' => 'Data dummy untuk demo/testing.',
                'created_by' => $user->id,
            ]);

            $material->recordMovement(
                'in',
                $data['qty'],
                $user->id,
                self::MARK_PREFIX . 'Stok awal untuk demo/testing.',
                Carbon::now()->subDays(20)->toDateString(),
                Carbon::now()->addMonths(6)->toDateString(),
                $data['cost']
            );

            $material->recordMovement(
                'out',
                round($data['qty'] * 0.3, 2),
                $user->id,
                self::MARK_PREFIX . 'Dipakai untuk instalasi demo/testing.'
            );

            return $material;
        });

        $this->info("{$materials->count()} bahan baku dummy dibuat (beserta riwayat masuk/keluar & batch).");

        return $materials;
    }

    /**
     * ConsumableItem::recordMovement() — sama pola dengan RawMaterial,
     * tanpa sistem batch (memang tidak ada untuk barang habis pakai).
     */
    private function createConsumableItems(User $user): \Illuminate\Support\Collection
    {
        $items = collect([
            ['name' => 'Lakban Kertas 3M', 'unit' => 'pcs', 'qty' => 30, 'cost' => 25000],
            ['name' => 'Cutter Blade Olfa', 'unit' => 'pcs', 'qty' => 50, 'cost' => 5000],
            ['name' => 'Cairan Pembersih Kaca', 'unit' => 'botol', 'qty' => 12, 'cost' => 35000],
        ])->map(function (array $data) use ($user) {
            $item = ConsumableItem::create([
                'name' => self::MARK_PREFIX . $data['name'],
                'code' => 'CI-DEMO' . Str::upper(Str::random(6)),
                'category' => 'Barang Habis Pakai Demo',
                'unit' => $data['unit'],
                'reorder_point' => round($data['qty'] * 0.2, 2),
                'notes' => 'Data dummy untuk demo/testing.',
                'created_by' => $user->id,
            ]);

            $item->recordMovement('in', $data['qty'], $user->id, self::MARK_PREFIX . 'Stok awal untuk demo/testing.', $data['cost']);
            $item->recordMovement('out', round($data['qty'] * 0.3, 2), $user->id, self::MARK_PREFIX . 'Dipakai untuk instalasi demo/testing.');

            return $item;
        });

        $this->info("{$items->count()} barang habis pakai dummy dibuat (beserta riwayat masuk/keluar).");

        return $items;
    }

    private function createAssets(Store $store, User $user): void
    {
        $assets = [
            ['name' => 'Heat Gun Steinel', 'category' => 'Mesin', 'status' => 'aktif', 'cost' => 2500000, 'years' => 5],
            ['name' => 'Meja Kerja Instalasi', 'category' => 'Furnitur', 'status' => 'aktif', 'cost' => 1500000, 'years' => 8],
            ['name' => 'AC Ruang Instalasi', 'category' => 'Elektronik', 'status' => 'diperbaiki', 'cost' => 4000000, 'years' => 6],
        ];

        foreach ($assets as $data) {
            Asset::create([
                'asset_tag' => 'ASSET-DEMO' . Str::upper(Str::random(6)),
                'name' => self::MARK_PREFIX . $data['name'],
                'category' => $data['category'],
                'received_date' => Carbon::now()->subMonths(rand(1, 12))->toDateString(),
                'status' => $data['status'],
                'store_id' => $store->id,
                'assigned_to' => $user->id,
                'purchase_date' => Carbon::now()->subMonths(rand(6, 24))->toDateString(),
                'purchase_cost' => $data['cost'],
                'useful_life_years' => $data['years'],
                'salvage_value' => round($data['cost'] * 0.1, 2),
                'notes' => 'Data dummy untuk demo/testing.',
                'created_by' => $user->id,
            ]);
        }

        $this->info(count($assets) . ' aset tetap dummy dibuat.');
    }

    /**
     * MaterialMemo::generateMemoNumber() dipakai ulang persis. Item memo
     * mengambil dari RawMaterial/ConsumableItem demo yang sudah dibuat —
     * qty_taken = qty_returned + qty_used, supaya angkanya masuk akal
     * (bukan sekadar kolom terisi random).
     */
    private function createMaterialMemos(Store $store, User $user, \Illuminate\Support\Collection $rawMaterials, \Illuminate\Support\Collection $consumableItems): void
    {
        $memos = [
            ['vehicle' => 'Toyota Fortuner Hitam B 1234 XYZ', 'spk' => 'SPK-DEMO-001'],
            ['vehicle' => 'Honda CR-V Putih B 5678 ABC', 'spk' => 'SPK-DEMO-002'],
        ];

        $count = 0;

        foreach ($memos as $i => $data) {
            $memo = new MaterialMemo([
                'store_id' => $store->id,
                'vehicle_info' => $data['vehicle'],
                'spk_number' => $data['spk'],
                'notes' => self::MARK_PREFIX . 'Memo dummy untuk demo/testing.',
                'created_by' => $user->id,
            ]);
            $memo->memo_number = MaterialMemo::generateMemoNumber();
            $memo->save();

            $material = $rawMaterials[$i % $rawMaterials->count()];
            $consumable = $consumableItems[$i % $consumableItems->count()];

            MaterialMemoItem::create([
                'material_memo_id' => $memo->id,
                'item_type' => 'raw_material',
                'item_id' => $material->id,
                'item_name' => $material->name,
                'unit' => $material->unit,
                'qty_taken' => 2,
                'qty_returned' => 0.5,
                'qty_used' => 1.5,
                'condition_notes' => self::MARK_PREFIX . 'Data dummy untuk demo/testing.',
            ]);

            MaterialMemoItem::create([
                'material_memo_id' => $memo->id,
                'item_type' => 'consumable_item',
                'item_id' => $consumable->id,
                'item_name' => $consumable->name,
                'unit' => $consumable->unit,
                'qty_taken' => 3,
                'qty_returned' => 0,
                'qty_used' => 3,
                'condition_notes' => self::MARK_PREFIX . 'Data dummy untuk demo/testing.',
            ]);

            $count++;
        }

        $this->info("{$count} memo pengambilan/pengembalian dummy dibuat.");
    }

    private function createPurchaseRequests(Store $store, User $user, \Illuminate\Support\Collection $rawMaterials, \Illuminate\Support\Collection $consumableItems): void
    {
        $requests = [
            ['item_type' => 'raw_material', 'item' => $rawMaterials->first(), 'status' => 'pending'],
            ['item_type' => 'consumable_item', 'item' => $consumableItems->first(), 'status' => 'approved'],
            ['item_type' => 'asset', 'item' => null, 'name' => self::MARK_PREFIX . 'Mesin Laminating Baru', 'status' => 'rejected'],
        ];

        $count = 0;

        foreach ($requests as $data) {
            $request = new PurchaseRequest([
                'store_id' => $store->id,
                'item_type' => $data['item_type'],
                'item_id' => $data['item']?->id,
                'item_name' => $data['item']?->name ?? $data['name'],
                'unit' => $data['item']?->unit ?? 'unit',
                'quantity' => $data['item_type'] === 'asset' ? 1 : 5,
                'reason' => self::MARK_PREFIX . 'Permohonan dummy untuk demo/testing.',
                'status' => $data['status'],
                'requested_by' => $user->id,
                'reviewed_by' => $data['status'] !== 'pending' ? $user->id : null,
                'reviewed_at' => $data['status'] !== 'pending' ? Carbon::now()->subDays(1) : null,
                'review_note' => $data['status'] === 'rejected' ? self::MARK_PREFIX . 'Ditolak untuk demo/testing.' : null,
                'fulfilled_at' => $data['status'] === 'fulfilled' ? Carbon::now() : null,
            ]);
            // request_number di-generate otomatis oleh booted()'s creating
            // hook (tidak dibungkus withoutEvents(), tidak ada Observer
            // terdaftar untuk model ini sama sekali).
            $request->save();

            $count++;
        }

        $this->info("{$count} permohonan pembelian dummy dibuat.");
    }

    private function cleanup(): int
    {
        $total = 0;

        $total += InventoryItem::where('name', 'like', self::MARK_PREFIX . '%')->delete();
        $total += RawMaterial::where('name', 'like', self::MARK_PREFIX . '%')->delete();
        $total += ConsumableItem::where('name', 'like', self::MARK_PREFIX . '%')->delete();
        $total += Asset::where('name', 'like', self::MARK_PREFIX . '%')->delete();
        $total += MaterialMemo::where('notes', 'like', self::MARK_PREFIX . '%')->delete();
        $total += PurchaseRequest::where('reason', 'like', self::MARK_PREFIX . '%')->delete();

        if ($total === 0) {
            $this->info('Tidak ada data demo untuk dibersihkan.');
            return self::SUCCESS;
        }

        $this->info("{$total} baris data dummy grup Inventaris sudah dihapus (riwayat/batch/item terkait ikut terhapus otomatis lewat cascadeOnDelete).");

        return self::SUCCESS;
    }
}
