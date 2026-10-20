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
    private function stockValue(RawMaterial|ConsumableItem $material): float
    {
        if ($material instanceof RawMaterial) {
            return $material->load('batches')->stockValue();
        }

        return (float) $material->current_stock * (float) ($material->unit_cost ?? 0);
    }

    /**
     * Selisih nilai persediaan hasil opname dijurnal ke Beban Kerugian Persediaan (6520) bila fitur persediaan di Buku Besar menyala:
     * kurang = Debit 6520 / Kredit persediaan, lebih = sebaliknya.
     *
     * @param  array<string, float>  $valueDelta kode akun persediaan => perubahan nilai
     */
    private function postValueDifference(StockOpname $opname, array $valueDelta, ?int $userId): void
    {
        if (! BookingCogsPostingService::enabled()) {
            return;
        }

        $lines = [];
        $net = 0.0;

        foreach ($valueDelta as $code => $delta) {
            $delta = round($delta, 2);

            if (abs($delta) < 1) {
                continue;
            }

            $inventory = \App\Models\ChartOfAccount::where('code', $code)->first();

            if (! $inventory) {
                return;
            }

            $lines[] = $delta > 0
                ? ['chart_of_account_id' => $inventory->id, 'debit' => $delta]
                : ['chart_of_account_id' => $inventory->id, 'credit' => abs($delta)];
            $net += $delta;
        }

        $loss = \App\Models\ChartOfAccount::where('code', '6520')->first();

        if (! $lines || ! $loss) {
            return;
        }

        $net = round($net, 2);

        if ($net > 0) {
            $lines[] = ['chart_of_account_id' => $loss->id, 'credit' => $net];
        } elseif ($net < 0) {
            $lines[] = ['chart_of_account_id' => $loss->id, 'debit' => abs($net)];
        }

        if (count($lines) < 2) {
            return;
        }

        $date = \Illuminate\Support\Carbon::parse($opname->opname_date);
        $entryDate = $date->isFuture() || \App\Models\AccountingPeriod::isClosedFor($date) ? now()->toDateString() : $date->toDateString();

        $service = app(JournalEntryService::class);
        $entry = $service->create([
            'entry_date' => $entryDate,
            'store_id' => $opname->store_id,
            'description' => "Selisih Stok Opname {$opname->opname_number}",
            'reference_type' => 'stock_opname',
            'reference_id' => $opname->id,
            'created_by' => $userId,
        ], $lines);
        $service->post($entry, $userId);
    }

    public function create(int $storeId, string $opnameDate, ?string $notes, array $items, ?int $userId): StockOpname
    {
        if (empty($items)) {
            throw new RuntimeException('Stok Opname butuh minimal 1 item yang dihitung.');
        }

        // Satu item hanya boleh dihitung sekali per sesi; angka negatif tidak masuk akal sebagai hasil hitung fisik.
        $seen = [];
        foreach ($items as $row) {
            $key = ($row['item_type'] ?? '') . ':' . ($row['item_id'] ?? '');

            if (isset($seen[$key])) {
                throw new RuntimeException('Item yang sama tidak boleh dihitung dua kali dalam satu sesi.');
            }
            $seen[$key] = true;

            if ((float) ($row['actual_quantity'] ?? 0) < 0) {
                throw new RuntimeException('Hasil hitung fisik tidak boleh negatif.');
            }
        }

        return DB::transaction(function () use ($storeId, $opnameDate, $notes, $items, $userId) {
            $opname = StockOpname::create([
                'opname_number' => StockOpname::generateOpnameNumber(),
                'store_id' => $storeId,
                'opname_date' => $opnameDate,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $valueDelta = ['1130' => 0.0, '1131' => 0.0];

            foreach ($items as $row) {
                // lockForUpdate: stok sistem yang dicatat di baris sesi harus sama dengan yang dipakai adjustStock().
                $material = match ($row['item_type']) {
                    'raw_material' => RawMaterial::query()->whereKey($row['item_id'])->lockForUpdate()->first(),
                    'consumable_item' => ConsumableItem::query()->whereKey($row['item_id'])->lockForUpdate()->first(),
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
                $valueBefore = $this->stockValue($material);
                $material->adjustStock($actualQuantity, $userId, "Stok Opname {$opname->opname_number}", $storeId);
                $valueDelta[$row['item_type'] === 'raw_material' ? '1130' : '1131'] += $this->stockValue($material->refresh()) - $valueBefore;

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

            $this->postValueDifference($opname, $valueDelta, $userId);

            return $opname->fresh('items');
        });
    }
}
