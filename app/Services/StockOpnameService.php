<?php

namespace App\Services;

use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Stok Opname" -- keputusan atasan 2026-09-19 (Topik 4, Fase 1,
 * "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx"). Mengelompokkan beberapa
 * item hitung fisik jadi 1 sesi per toko/tanggal, TAPI penyesuaian
 * kuantitasnya sendiri tetap lewat RawMaterial::adjustStock()/
 * ConsumableItem::adjustStock() yang SUDAH ADA & teruji (FIFO batch,
 * lockForUpdate) -- service ini TIDAK menduplikasi logika itu, cuma
 * membungkusnya + menandai store_id + mengelompokkan jadi 1 sesi.
 *
 * TIDAK posting jurnal untuk NILAI selisih (beda dari StockWriteOffService)
 * -- lihat catatan lengkap kenapa di migrasi create_stock_opnames_table.
 *
 * @see RawMaterial::adjustStock()
 * @see ConsumableItem::adjustStock()
 */
class StockOpnameService
{
    /**
     * @param  array<int, array{item_type: string, item_id: int, actual_quantity: float}>  $items
     *
     * @throws RuntimeException kalau $items kosong, atau ada item_type
     *         yang tidak dikenal / item_id yang tidak ditemukan.
     */
    public function create(int $storeId, string $opnameDate, ?string $notes, array $items, ?int $userId): StockOpname
    {
        if (empty($items)) {
            throw new RuntimeException('Stok Opname butuh minimal 1 item yang dihitung.');
        }

        return DB::transaction(function () use ($storeId, $opnameDate, $notes, $items, $userId) {
            $opname = StockOpname::create([
                'opname_number' => StockOpname::generateOpnameNumber(),
                'store_id' => $storeId,
                'opname_date' => $opnameDate,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            foreach ($items as $row) {
                $material = match ($row['item_type']) {
                    'raw_material' => RawMaterial::find($row['item_id']),
                    'consumable_item' => ConsumableItem::find($row['item_id']),
                    default => throw new RuntimeException("Jenis item tidak dikenal: {$row['item_type']}"),
                };

                if (! $material) {
                    throw new RuntimeException("Item {$row['item_type']} #{$row['item_id']} tidak ditemukan.");
                }

                $systemQuantity = (float) $material->current_stock;
                $actualQuantity = (float) $row['actual_quantity'];
                $delta = round($actualQuantity - $systemQuantity, 2);

                // adjustStock() SENDIRI yang mem-validasi & mengurus FIFO
                // batch/lockForUpdate -- kalau delta < 0.01 dia return
                // null (tidak ada movement), baris StockOpnameItem TETAP
                // dicatat (jejak "sudah dihitung, hasilnya sama") biarpun
                // tidak ada movement yang menyertainya.
                $material->adjustStock($actualQuantity, $userId, "Stok Opname {$opname->opname_number}", $storeId);

                StockOpnameItem::create([
                    'stock_opname_id' => $opname->id,
                    'item_type' => $row['item_type'],
                    'item_id' => $material->id,
                    'item_name' => $material->name,
                    'unit' => $material->unit,
                    'system_quantity' => $systemQuantity,
                    'actual_quantity' => $actualQuantity,
                    'delta' => $delta,
                ]);
            }

            return $opname->fresh('items');
        });
    }
}
