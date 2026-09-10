<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use App\Models\StockWriteOff;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Write-off stok bahan baku / barang habis pakai — diminta 2026-09-10
 * ("Stok Terbuang" Majoo). Alur:
 *   1. Kurangi stok lewat $item->adjustStock() (movement type
 *      'adjustment', delta negatif) — jalur SAMA dengan Opname, TIDAK
 *      bikin logika stok sendiri.
 *   2. Kalau unit_cost > 0: posting JournalEntry Debit 6520 (Beban
 *      Kerugian Persediaan), Kredit 1130 (Bahan Baku) / 1131 (Barang
 *      Habis Pakai) senilai qty x unit_cost. Kalau unit_cost belum
 *      diisi, jurnal DILEWATI (tidak bisa dinilai) — write-off tetap
 *      tercatat.
 *   3. Simpan baris StockWriteOff untuk laporan "Stok Terbuang".
 */
class StockWriteOffService
{
    private const LOSS_ACCOUNT_CODE = '6520';
    private const RAW_MATERIAL_INVENTORY_CODE = '1130';
    private const CONSUMABLE_INVENTORY_CODE = '1131';

    /**
     * @param  RawMaterial|ConsumableItem  $item
     *
     * @throws RuntimeException
     */
    public function record($item, string $itemType, float $quantity, string $reason, ?string $note, ?int $userId): StockWriteOff
    {
        if (! in_array($itemType, ['raw_material', 'consumable_item'], true)) {
            throw new RuntimeException("Jenis barang tidak dikenal: {$itemType}");
        }

        if ($quantity <= 0) {
            throw new RuntimeException('Jumlah write-off harus lebih dari 0.');
        }

        if (! array_key_exists($reason, StockWriteOff::REASON_LABELS)) {
            throw new RuntimeException("Alasan tidak dikenal: {$reason}");
        }

        return DB::transaction(function () use ($item, $itemType, $quantity, $reason, $note, $userId) {
            $current = (float) $item->current_stock;

            if ($quantity > $current) {
                throw new RuntimeException("Jumlah write-off ({$quantity} {$item->unit}) melebihi stok saat ini ({$current} {$item->unit}).");
            }

            $reasonLabel = StockWriteOff::REASON_LABELS[$reason];
            $movementNote = "Stok terbuang ({$reasonLabel})" . ($note ? " — {$note}" : '');

            $item->adjustStock($current - $quantity, $userId, $movementNote);
            $item->refresh();

            $unitCost = $item->unit_cost !== null ? (float) $item->unit_cost : null;
            $totalValue = ($unitCost !== null && $unitCost > 0) ? round($quantity * $unitCost, 2) : null;

            $journalId = null;

            if ($totalValue !== null) {
                $lossAccount = ChartOfAccount::where('code', self::LOSS_ACCOUNT_CODE)->first();
                $inventoryCode = $itemType === 'raw_material'
                    ? self::RAW_MATERIAL_INVENTORY_CODE
                    : self::CONSUMABLE_INVENTORY_CODE;
                $inventoryAccount = ChartOfAccount::where('code', $inventoryCode)->first();

                if (! $lossAccount || ! $inventoryAccount) {
                    throw new RuntimeException('Akun jurnal (' . self::LOSS_ACCOUNT_CODE . " / {$inventoryCode}) tidak ditemukan di Bagan Akun.");
                }

                $service = app(JournalEntryService::class);
                $entry = $service->create([
                    'entry_date' => now()->toDateString(),
                    'store_id' => null, // persediaan masih nasional
                    'description' => "Stok terbuang ({$reasonLabel}) — {$item->name}, " . rtrim(rtrim(number_format($quantity, 2), '0'), '.') . " {$item->unit}" . ($note ? " — {$note}" : ''),
                    'reference_type' => 'stock_write_off',
                    'created_by' => $userId,
                ], [
                    ['chart_of_account_id' => $lossAccount->id, 'debit' => $totalValue],
                    ['chart_of_account_id' => $inventoryAccount->id, 'credit' => $totalValue],
                ]);
                $entry = $service->post($entry, $userId);
                $journalId = $entry->id;
            }

            return StockWriteOff::create([
                'write_off_number' => StockWriteOff::generateNumber(),
                'writeoffable_type' => $itemType,
                'writeoffable_id' => $item->id,
                'item_name' => $item->name,
                'unit' => $item->unit,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_value' => $totalValue,
                'reason' => $reason,
                'note' => $note,
                'store_id' => null,
                'journal_entry_id' => $journalId,
                'created_by' => $userId,
            ]);
        });
    }
}
