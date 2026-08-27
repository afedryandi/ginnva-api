<?php

namespace App\Services;

use App\Models\ConsumableItem;
use App\Models\InventoryItem;
use App\Models\MaterialMemo;
use App\Models\MaterialMemoItem;
use App\Models\RawMaterial;
use App\Models\ScrollCode;
use App\Models\ScrollCodeUsage;
use Illuminate\Support\Facades\DB;

/**
 * Semua logika "efek stok" dari fitur Memo Pengambilan/Pengembalian —
 * DISATUKAN di sini supaya API (MaterialMemoController) dan Filament
 * (ItemsRelationManager, EditMaterialMemo) selalu pakai jalur yang SAMA
 * PERSIS. Sebelum refactor ini, dua tempat itu masing-masing punya
 * salinan logika sendiri yang lama-lama nyimpang (Filament tidak ikut
 * dapat fitur koreksi/hapus baris waktu itu ditambahkan cuma di API).
 */
class MaterialMemoStockService
{
    public static function buildNote(MaterialMemo $memo, string $suffix, ?string $conditionNotes = null): string
    {
        $note = "Memo {$memo->memo_number} — {$suffix}";

        return $conditionNotes ? "{$note}: {$conditionNotes}" : $note;
    }

    /**
     * @param  RawMaterial|ConsumableItem  $material
     */
    public static function addMaterial($material, string $itemType, MaterialMemo $memo, float $qtyTaken, int $userId, ?string $conditionNotes): MaterialMemoItem
    {
        return DB::transaction(function () use ($material, $itemType, $memo, $qtyTaken, $userId, $conditionNotes) {
            $material->recordMovement('out', $qtyTaken, $userId, self::buildNote($memo, 'pengambilan', $conditionNotes));

            return MaterialMemoItem::create([
                'material_memo_id' => $memo->id,
                'item_type' => $itemType,
                'item_id' => $material->id,
                'item_name' => $material->name,
                'unit' => $material->unit,
                'qty_taken' => $qtyTaken,
                'condition_notes' => $conditionNotes,
            ]);
        });
    }

    /**
     * @throws \InvalidArgumentException  barang tidak punya kode gulungan
     */
    public static function addInventory(InventoryItem $item, MaterialMemo $memo, float $meters, int $userId, ?string $conditionNotes): MaterialMemoItem
    {
        if (! $item->scroll_code_id) {
            throw new \InvalidArgumentException('Barang ini tidak punya kode gulungan terkait.');
        }

        $scrollCode = ScrollCode::findOrFail($item->scroll_code_id);

        return DB::transaction(function () use ($scrollCode, $item, $memo, $meters, $userId, $conditionNotes) {
            $scrollCode->recordUsage($meters, $userId, self::buildNote($memo, 'pemakaian gulungan', $conditionNotes));
            // recordUsage() tidak return baris riwayatnya — ambil yang baru
            // saja (masih di transaction yang sama) supaya bisa dikoreksi/
            // dihapus lagi belakangan kalau ternyata salah input.
            $usage = $scrollCode->usages()->latest('id')->first();

            return MaterialMemoItem::create([
                'material_memo_id' => $memo->id,
                'item_type' => 'inventory_item',
                'item_id' => $item->id,
                'item_name' => $item->name . ' (' . $scrollCode->code . ')',
                'unit' => 'meter',
                'meters_used' => $meters,
                'scroll_code_usage_id' => $usage?->id,
                'condition_notes' => $conditionNotes,
            ]);
        });
    }

    /**
     * @param  float|null  $unitCost  Opsional — harga per satuan yang
     *         BENAR untuk barang yang dikembalikan ini. Kalau tidak
     *         diisi, RawMaterial::recordMovement()/ConsumableItem::recordMovement()
     *         otomatis pakai "harga terakhir tersimpan" sebagai perkiraan
     *         (lihat catatan di kedua method itu) — TIDAK ada cara sistem
     *         tahu harga batch asli yang dulu dikonsumsi saat pengambilan
     *         (movement 'out' tidak pernah menyimpan unit_cost), jadi
     *         parameter ini murni supaya admin yang KEBETULAN tahu harga
     *         sebenarnya bisa isi manual, bukan solusi otomatis.
     *
     * @throws \InvalidArgumentException
     */
    public static function returnMaterial(MaterialMemoItem $memoItem, float $qtyReturned, int $userId, MaterialMemo $memo, ?float $unitCost = null): void
    {
        if (! in_array($memoItem->item_type, ['raw_material', 'consumable_item'], true)) {
            throw new \InvalidArgumentException('Jenis barang ini tidak punya alur pengembalian.');
        }

        if ($memoItem->qty_returned !== null) {
            throw new \InvalidArgumentException('Pengembalian untuk baris ini sudah pernah dicatat.');
        }

        if ($qtyReturned < 0 || $qtyReturned > (float) $memoItem->qty_taken) {
            throw new \InvalidArgumentException("Jumlah dikembalikan harus antara 0 dan {$memoItem->qty_taken} {$memoItem->unit}.");
        }

        $material = $memoItem->resolveItem();

        if (! $material) {
            throw new \InvalidArgumentException('Barang aslinya sudah tidak ada di sistem.');
        }

        DB::transaction(function () use ($material, $memoItem, $qtyReturned, $userId, $memo, $unitCost) {
            // Lock baris memo item supaya 2 aksi "kembalikan" yang nyaris
            // bersamaan pada baris yang sama tidak bisa dobel-dobel lolos
            // dari pengecekan qty_returned !== null di atas.
            $locked = MaterialMemoItem::where('id', $memoItem->id)->lockForUpdate()->firstOrFail();

            if ($locked->qty_returned !== null) {
                throw new \InvalidArgumentException('Pengembalian untuk baris ini sudah pernah dicatat.');
            }

            if ($qtyReturned > 0) {
                // Named argument SENGAJA dipakai — RawMaterial::recordMovement()
                // dan ConsumableItem::recordMovement() punya $unitCost di
                // POSISI BERBEDA (ke-7 vs ke-5), lewat posisi biasa bisa
                // salah masuk ke parameter lain (mis. jadi $receivedDate
                // di RawMaterial) karena $material union type keduanya.
                $material->recordMovement('in', $qtyReturned, $userId, self::buildNote($memo, 'pengembalian'), unitCost: $unitCost);
            }

            $locked->update([
                'qty_returned' => $qtyReturned,
                'qty_used' => $locked->qty_taken - $qtyReturned,
            ]);
        });
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function updateMaterialQty(MaterialMemoItem $memoItem, float $newQty, int $userId, MaterialMemo $memo, ?float $unitCost = null): void
    {
        if ($newQty <= 0) {
            throw new \InvalidArgumentException('Jumlah harus lebih besar dari 0.');
        }

        $material = $memoItem->resolveItem();

        if (! $material) {
            throw new \InvalidArgumentException('Barang aslinya sudah tidak ada di sistem.');
        }

        DB::transaction(function () use ($material, $memoItem, $newQty, $userId, $memo, $unitCost) {
            $locked = MaterialMemoItem::where('id', $memoItem->id)->lockForUpdate()->firstOrFail();

            if ($locked->qty_returned !== null) {
                throw new \InvalidArgumentException('Baris ini sudah ada pengembaliannya — tidak bisa diedit lagi. Kalau memang salah, hubungi admin.');
            }

            $delta = round($newQty - (float) $locked->qty_taken, 2);

            if ($delta === 0.0) {
                return;
            }

            $note = self::buildNote($memo, 'koreksi jumlah');

            if ($delta > 0) {
                $material->recordMovement('out', $delta, $userId, $note);
            } else {
                // Named argument — lihat catatan di returnMaterial().
                $material->recordMovement('in', abs($delta), $userId, $note, unitCost: $unitCost);
            }

            $locked->update(['qty_taken' => $newQty]);
        });
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function updateInventoryQty(MaterialMemoItem $memoItem, float $newMeters, MaterialMemo $memo): void
    {
        if ($newMeters <= 0) {
            throw new \InvalidArgumentException('Jumlah harus lebih besar dari 0.');
        }

        $scrollCodeId = InventoryItem::find($memoItem->item_id)?->scroll_code_id;
        $scrollCode = $scrollCodeId ? ScrollCode::find($scrollCodeId) : null;

        if (! $scrollCode) {
            throw new \InvalidArgumentException('Kode gulungan aslinya sudah tidak ada di sistem.');
        }

        $oldMeters = (float) $memoItem->meters_used;
        $delta = round($newMeters - $oldMeters, 2);

        if ($delta === 0.0) {
            return;
        }

        DB::transaction(function () use ($scrollCode, $memoItem, $newMeters, $delta, $memo) {
            $locked = ScrollCode::where('id', $scrollCode->id)->lockForUpdate()->firstOrFail();

            if ($delta > (float) $locked->remaining_length_meters) {
                throw new \InvalidArgumentException("Meter tidak cukup — sisa panjang gulungan cuma {$locked->remaining_length_meters} meter.");
            }

            $remaining = round((float) $locked->remaining_length_meters - $delta, 2);

            if ($remaining > (float) $locked->total_length_meters + 0.01) {
                // Selisih koreksi lebih besar dari total panjang gulungan
                // itu sendiri — tanda data sudah tidak konsisten, tolak
                // daripada diam-diam dipotong (silent clamp).
                throw new \InvalidArgumentException('Koreksi ini membuat sisa panjang melebihi total panjang gulungan — periksa lagi data gulungannya.');
            }

            $remaining = min($remaining, (float) $locked->total_length_meters);
            $wasUsedByThis = $locked->status === 'used' && self::isLatestUsage($locked->id, $memoItem->scroll_code_usage_id);

            $locked->update([
                'remaining_length_meters' => $remaining,
                // Status 'used' cuma dibuka lagi kalau baris YANG SEDANG
                // DIKOREKSI ini adalah pemakaian PALING TERAKHIR di
                // gulungan itu — kalau ada pemakaian lain yang lebih baru
                // (mis. staff lain sudah pakai sisa gulungan ini juga),
                // status 'used' dibiarkan apa adanya supaya tidak salah
                // "membuka" gulungan yang sebenarnya sudah benar-benar habis.
                'status' => $remaining <= 0 ? 'used' : ($wasUsedByThis ? 'allocated' : $locked->status),
                'used_at' => $remaining <= 0 ? ($locked->used_at ?? now()) : ($wasUsedByThis ? null : $locked->used_at),
            ]);

            if ($memoItem->scroll_code_usage_id) {
                ScrollCodeUsage::where('id', $memoItem->scroll_code_usage_id)
                    ->update(['meters' => $newMeters, 'note' => self::buildNote($memo, 'koreksi jumlah')]);
            }

            $memoItem->update(['meters_used' => $newMeters]);
        });
    }

    public static function reverseItem(MaterialMemoItem $memoItem, ?int $userId, MaterialMemo $memo): void
    {
        if ($memoItem->item_type === 'inventory_item') {
            self::reverseInventoryItem($memoItem);
        } else {
            self::reverseMaterialItem($memoItem, $userId, $memo);
        }
    }

    private static function reverseMaterialItem(MaterialMemoItem $memoItem, ?int $userId, MaterialMemo $memo): void
    {
        $material = $memoItem->resolveItem();

        if (! $material) {
            return;
        }

        // Kalau sudah ada pengembalian, yang masih "keluar" dari stok cuma
        // qty_used (qty_taken - qty_returned) — qty_returned-nya sendiri
        // sudah balik ke stok lewat returnMaterial(), jangan dikembalikan dobel.
        $stillOut = $memoItem->qty_returned !== null
            ? (float) $memoItem->qty_used
            : (float) $memoItem->qty_taken;

        if ($stillOut > 0) {
            $material->recordMovement('in', $stillOut, $userId, self::buildNote($memo, 'baris dihapus'));
        }
    }

    private static function reverseInventoryItem(MaterialMemoItem $memoItem): void
    {
        $scrollCodeId = InventoryItem::find($memoItem->item_id)?->scroll_code_id;
        $scrollCode = $scrollCodeId ? ScrollCode::find($scrollCodeId) : null;

        if (! $scrollCode) {
            return;
        }

        // Lewat ScrollCode::reverseUsage() — method KANONIK yang juga
        // mengurangi usage_count (SEBELUMNYA method ini duplikat logika
        // manual dari sebelum reverseUsage() ada, dan TIDAK PERNAH
        // menyentuh usage_count sama sekali, jadi tiap baris memo PPF/WF
        // yang dihapus bikin usage_count ScrollCode makin menyimpang dari
        // jumlah riwayat pemakaian yang sebenarnya masih ada).
        if ($memoItem->scroll_code_usage_id) {
            $usage = ScrollCodeUsage::find($memoItem->scroll_code_usage_id);

            if ($usage) {
                $scrollCode->reverseUsage($usage);

                return;
            }
        }

        // Fallback untuk baris memo LAMA yang dibuat sebelum kolom
        // scroll_code_usage_id ada (tidak ada baris usage utuh untuk
        // dikaitkan) — usage_count TIDAK bisa dikoreksi di jalur ini
        // karena tidak ada baris usage yang menunjukkan kontribusinya.
        DB::transaction(function () use ($scrollCode, $memoItem) {
            $locked = ScrollCode::where('id', $scrollCode->id)->lockForUpdate()->firstOrFail();
            $meters = (float) $memoItem->meters_used;
            $remaining = min(
                round((float) $locked->remaining_length_meters + $meters, 2),
                (float) $locked->total_length_meters
            );
            $wasUsedByThis = $locked->status === 'used' && self::isLatestUsage($locked->id, $memoItem->scroll_code_usage_id);

            $locked->update([
                'remaining_length_meters' => $remaining,
                'status' => $remaining > 0 && $wasUsedByThis ? 'allocated' : $locked->status,
                'used_at' => $remaining > 0 && $wasUsedByThis ? null : $locked->used_at,
            ]);
        });
    }

    /**
     * Cek apakah $usageId adalah baris riwayat PALING BARU untuk gulungan
     * ini (dibandingkan dari id, karena auto-increment = urutan waktu).
     * Kalau ada baris riwayat lain yang lebih baru (id lebih besar), berarti
     * ada pemakaian lain setelah baris ini — jangan diam-diam buka lagi
     * status 'used' gulungannya.
     */
    private static function isLatestUsage(int $scrollCodeId, ?int $usageId): bool
    {
        if (! $usageId) {
            return false;
        }

        $latestId = ScrollCodeUsage::where('scroll_code_id', $scrollCodeId)->max('id');

        return $latestId !== null && (int) $latestId === $usageId;
    }

    /**
     * Membalik SEMUA baris di 1 memo — dipakai saat memo utuh dihapus,
     * supaya stok/sisa meter yang sudah terpakai tetap dikembalikan
     * (bukan cuma cascade-delete baris DB tanpa efek balik stok).
     */
    public static function reverseAllItems(MaterialMemo $memo, ?int $userId): void
    {
        foreach ($memo->items as $item) {
            self::reverseItem($item, $userId, $memo);
        }
    }
}
