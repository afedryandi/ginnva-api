<?php

namespace App\Services;

use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use Illuminate\Support\Carbon;
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
 * Audit 2026-11-03: alur DUA TAHAP.
 *   1. submit()  -- hasil hitung disimpan sebagai DRAF; stok sistem di-snapshot saat itu, stok BELUM berubah.
 *   2. apply()   -- akses penuh meninjau selisih lalu menerapkannya. Selisih (hasil hitung - snapshot) diterapkan RELATIF pada stok
 *                   terkini, jadi barang yang keluar/masuk di antara menghitung dan menerapkan tidak merusak hasilnya.
 *   discard()    -- draf yang tidak jadi diterapkan dibuang (sesi tetap tersimpan, bertanda Dibuang).
 * create() = submit + apply sekaligus (untuk pemanggil yang memang menerapkan langsung).
 *
 * Aturan catatan selisih besar (adjustment_note_threshold per barang) berlaku di submit(), sama dengan opname satu per satu.
 * Jurnal selisih nilai (6520) hanya dibuat saat apply(), bila fitur persediaan di Buku Besar menyala.
 *
 * @see RawMaterial::adjustStock()
 * @see ConsumableItem::adjustStock()
 */
class StockOpnameService
{
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

        $date = Carbon::parse($opname->opname_date);
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

    private function findItem(string $type, int $id, bool $lock = false): RawMaterial|ConsumableItem|null
    {
        $query = match ($type) {
            'raw_material' => RawMaterial::query(),
            'consumable_item' => ConsumableItem::query(),
            default => throw new RuntimeException("Jenis item tidak dikenal: {$type}"),
        };

        return $query->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    /**
     * Simpan hasil hitung sebagai DRAF (stok belum berubah).
     *
     * @param  array<int, array{item_type: string, item_id: int, actual_quantity: float, note?: ?string}>  $items
     *
     * @throws RuntimeException kalau $items kosong, item duplikat/tidak dikenal/tidak ditemukan, tanggal di masa depan.
     * @throws OpnameNeedsNoteException bila ada item yang selisihnya melewati ambang catatan tanpa catatan.
     */
    public function submit(int $storeId, string $opnameDate, ?string $notes, array $items, ?int $userId): StockOpname
    {
        if (empty($items)) {
            throw new RuntimeException('Stok Opname butuh minimal 1 item yang dihitung.');
        }

        if (Carbon::parse($opnameDate)->startOfDay()->gt(today())) {
            throw new RuntimeException('Tanggal opname tidak boleh di masa depan.');
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
                'status' => StockOpname::STATUS_DRAFT,
                'snapshot_at' => now(),
                'created_by' => $userId,
            ]);

            $problems = [];

            foreach ($items as $row) {
                $material = $this->findItem($row['item_type'], (int) $row['item_id']);

                if (! $material) {
                    throw new RuntimeException("Item {$row['item_type']} #{$row['item_id']} tidak ditemukan.");
                }

                $snapshot = (float) $material->current_stock;
                $actual = (float) $row['actual_quantity'];
                $note = isset($row['note']) ? trim((string) $row['note']) : '';

                if ($material->adjustmentNeedsNote($actual) && $note === '') {
                    $limit = rtrim(rtrim(number_format((float) $material->adjustment_note_threshold, 2, '.', ''), '0'), '.');
                    $problems[] = [
                        'item_type' => $row['item_type'],
                        'item_id' => $material->id,
                        'message' => "{$material->name}: selisih melebihi {$limit} {$material->unit}, isi catatan alasan.",
                    ];
                }

                StockOpnameItem::create([
                    'stock_opname_id' => $opname->id,
                    'item_type' => $row['item_type'],
                    'item_id' => $material->id,
                    'item_name' => $material->name,
                    'unit' => $material->unit,
                    'system_quantity' => $snapshot,
                    'actual_quantity' => $actual,
                    'delta' => round($actual - $snapshot, 2),
                    'note' => $note !== '' ? $note : null,
                ]);
            }

            if ($problems !== []) {
                throw new OpnameNeedsNoteException($problems);
            }

            return $opname->fresh('items');
        });
    }

    /**
     * Terapkan draf: selisih hasil hitung (terhadap snapshot) ditambahkan ke stok terkini lewat adjustStock().
     *
     * @throws RuntimeException bila bukan draf, atau salah satu itemnya sudah dihapus
     */
    public function apply(StockOpname $opname, ?int $userId): StockOpname
    {
        return DB::transaction(function () use ($opname, $userId) {
            $locked = StockOpname::withoutGlobalScopes()->whereKey($opname->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockOpname::STATUS_DRAFT) {
                throw new RuntimeException('Sesi ini sudah diterapkan atau dibuang.');
            }

            $valueDelta = ['1130' => 0.0, '1131' => 0.0];

            foreach ($locked->items()->get() as $row) {
                // lockForUpdate: stok terkini yang dipakai menghitung target harus sama dengan yang dipakai adjustStock().
                $material = $this->findItem($row->item_type, $row->item_id, true);

                if (! $material) {
                    throw new RuntimeException("Item \"{$row->item_name}\" sudah dihapus, jadi sesi tidak bisa diterapkan. Buang draf ini dan hitung ulang.");
                }

                $current = (float) $material->current_stock;
                $target = max(0.0, round($current + (float) $row->delta, 2));

                // adjustStock() SENDIRI yang mem-validasi & mengurus FIFO batch -- kalau selisih < 0.01 dia return null
                // (tidak ada movement); baris sesi TETAP tercatat sebagai jejak "sudah dihitung".
                $valueBefore = $this->stockValue($material);
                $movement = $material->adjustStock($target, $userId, "Stok Opname {$locked->opname_number}", $locked->store_id, 'stock_opname');
                $movement?->update(['source_id' => $locked->id]);
                $valueDelta[$row->item_type === 'raw_material' ? '1130' : '1131'] += $this->stockValue($material->refresh()) - $valueBefore;

                $row->update(['applied_system_quantity' => $current]);
            }

            $this->postValueDifference($locked, $valueDelta, $userId);

            $locked->update(['status' => StockOpname::STATUS_APPLIED, 'applied_at' => now(), 'applied_by' => $userId]);

            return StockOpname::withoutGlobalScopes()->with('items')->findOrFail($locked->id);
        });
    }

    /** Buang draf yang tidak jadi diterapkan (stok tidak pernah berubah). Sesi tetap tersimpan bertanda Dibuang. */
    public function discard(StockOpname $opname, ?int $userId, string $reason): StockOpname
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Isi alasan membuang draf.');
        }

        return DB::transaction(function () use ($opname, $userId, $reason) {
            $locked = StockOpname::withoutGlobalScopes()->whereKey($opname->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockOpname::STATUS_DRAFT) {
                throw new RuntimeException('Hanya draf yang bisa dibuang. Sesi yang sudah diterapkan dikoreksi dengan menghitung ulang.');
            }

            $locked->update(['status' => StockOpname::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $userId, 'cancel_reason' => $reason]);

            return StockOpname::withoutGlobalScopes()->with('items')->findOrFail($locked->id);
        });
    }

    /** Simpan lalu langsung terapkan (satu langkah), mis. admin yang menghitung sendiri. */
    public function create(int $storeId, string $opnameDate, ?string $notes, array $items, ?int $userId): StockOpname
    {
        return $this->apply($this->submit($storeId, $opnameDate, $notes, $items, $userId), $userId);
    }
}
