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

        // Tanpa penjelasan, "hilang" dan "lainnya" tidak bisa ditelusuri (audit Stok Terbuang 2026-11-03).
        if (in_array($reason, StockWriteOff::REASONS_NEEDING_NOTE, true) && trim((string) $note) === '') {
            throw new RuntimeException('Catatan wajib diisi untuk alasan "' . StockWriteOff::REASON_LABELS[$reason] . '".');
        }

        return DB::transaction(function () use ($item, $itemType, $quantity, $reason, $note, $userId) {
            // Audit framework 2026-09-14, "Integritas transaksi
            // finansial" -- SEBELUMNYA $item->current_stock dibaca dari
            // instance yang di-load SEBELUM transaksi/lock ini, jadi 2
            // write-off bersamaan pada barang yang sama bisa
            // menghitung 2 target stok absolut berbeda dari data basi
            // yang sama, dan yang commit belakangan diam-diam MENIMPA
            // hasil yang commit duluan dengan angka salah (adjustStock()
            // set stok ke nilai ABSOLUT, bukan pengurangan relatif).
            // lockForUpdate() di sini DULU sebelum baca current_stock
            // memastikan snapshot-nya konsisten -- adjustStock() di
            // bawah re-lock baris yang SAMA lagi, aman (koneksi/transaksi
            // yang sama, bukan deadlock).
            $item = $item::query()->where('id', $item->id)->lockForUpdate()->firstOrFail();
            $current = (float) $item->current_stock;

            if ($quantity > $current) {
                throw new RuntimeException("Jumlah write-off ({$quantity} {$item->unit}) melebihi stok saat ini ({$current} {$item->unit}).");
            }

            $reasonLabel = StockWriteOff::REASON_LABELS[$reason];
            $movementNote = "Stok terbuang ({$reasonLabel})" . ($note ? " — {$note}" : '');

            $recorder = $userId ? \App\Models\User::find($userId) : null;

            // Kedaluwarsa: kurangi batch yang sudah lewat masa pakainya lebih dulu (hanya bahan baku punya batch).
            $movement = $item instanceof RawMaterial
                ? $item->adjustStock($current - $quantity, $userId, $movementNote, $recorder?->store_id, 'stock_write_off', $reason === 'expired')
                : $item->adjustStock($current - $quantity, $userId, $movementNote, $recorder?->store_id, 'stock_write_off');
            $item->refresh();

            // Nilai kerugian memakai harga rata-rata batch yang benar-benar dikurangi; bila tidak ada (barang habis pakai, atau batch tanpa
            // harga) jatuh ke harga terakhir barang.
            $unitCost = $movement?->unit_cost !== null && (float) $movement->unit_cost > 0
                ? (float) $movement->unit_cost
                : ($item->unit_cost !== null ? (float) $item->unit_cost : null);
            $totalValue = ($unitCost !== null && $unitCost > 0) ? round($quantity * $unitCost, 2) : null;

            $journalId = null;

            // Nilai tetap dicatat di baris Stok Terbuang; jurnalnya hanya dibuat bila modul Keuangan menyala (FEATURE_FINANCE).
            if ($totalValue !== null && config('features.finance')) {
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

            // Penanda toko = toko pencatat (staf non-full-access). SEBELUMNYA selalu NULL, padahal daftar "Stok Terbuang" dan
            // Global Scope memfilter staf ke store_id akunnya -- staf tidak pernah melihat satu pun write-off, termasuk yang
            // ia catat sendiri. Full-access tidak terikat toko, jadi catatannya tetap NULL (terlihat oleh full-access).
            $recorderStoreId = ($recorder && ! $recorder->isFullAccess()) ? $recorder->store_id : null;

            $writeOff = StockWriteOff::create([
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
                'store_id' => $recorderStoreId,
                'journal_entry_id' => $journalId,
                'created_by' => $userId,
            ]);

            // Taut pergerakan stok ke dokumen Stok Terbuang-nya, supaya tidak bisa dibatalkan dari Riwayat Bahan Baku.
            $movement?->update(['source_id' => $writeOff->id]);

            return $writeOff;
        });
    }

    /**
     * Batalkan Stok Terbuang yang salah catat (audit 2026-11-03): stok dipulihkan, jurnal kerugian dibalik bila ada, pergerakan stoknya
     * ditandai dibatalkan (tersembunyi dari laporan) dengan baris Koreksi sebagai jejak, dan baris ini ditandai Dibatalkan. Bahan baku
     * dipulihkan sebagai batch "tidak diketahui asalnya" (tanpa kedaluwarsa), karena sistem tidak menyimpan batch mana yang dikurangi.
     * Tidak harus pergerakan terakhir: jumlahnya sekadar dikembalikan ke stok, jadi urutan tidak berpengaruh.
     *
     * @throws RuntimeException bila sudah dibatalkan, barangnya sudah dihapus, atau jurnal pembaliknya gagal (mis. periode ditutup)
     */
    public function cancel(StockWriteOff $writeOff, ?int $userId, string $reason): StockWriteOff
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Isi alasan pembatalan.');
        }

        return DB::transaction(function () use ($writeOff, $userId, $reason) {
            $locked = StockWriteOff::withoutGlobalScopes()->whereKey($writeOff->id)->lockForUpdate()->firstOrFail();

            if ($locked->cancelled_at !== null) {
                throw new RuntimeException('Stok terbuang ini sudah dibatalkan.');
            }

            $itemClass = $locked->writeoffable_type === 'raw_material' ? RawMaterial::class : ConsumableItem::class;
            $item = $itemClass::query()->where('id', $locked->writeoffable_id)->lockForUpdate()->first();

            if ($item === null) {
                throw new RuntimeException('Barangnya sudah dihapus, jadi stoknya tidak bisa dipulihkan.');
            }

            $quantity = (float) $locked->quantity;
            $item->update(['current_stock' => (float) $item->current_stock + $quantity]);

            if ($item instanceof RawMaterial) {
                $item->batches()->create([
                    'quantity' => $quantity,
                    'unit_cost' => $locked->unit_cost ?? $item->unit_cost,
                    'received_date' => now()->toDateString(),
                    'expiry_date' => null,
                    'is_adjustment' => true,
                    'is_restoration' => true,
                    'created_by' => $userId,
                ]);
            }

            $movementClass = $item instanceof RawMaterial ? \App\Models\RawMaterialMovement::class : \App\Models\ConsumableItemMovement::class;
            $movement = $movementClass::withoutGlobalScope('effective')
                ->where('source_type', 'stock_write_off')->where('source_id', $locked->id)->first();

            $movement?->update(['reversed_at' => now(), 'reversed_by' => $userId, 'reversed_reason' => 'Pembatalan Stok Terbuang ' . $locked->write_off_number . ': ' . $reason]);

            $item->movements()->create([
                'type' => 'correction',
                'quantity' => 0,
                'note' => "Koreksi: membatalkan Stok Terbuang {$locked->write_off_number} (" . rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.') . " {$item->unit}). Alasan: {$reason}",
                'source_type' => 'stock_write_off',
                'source_id' => $locked->id,
                'user_id' => $userId,
                'store_id' => $movement?->store_id,
            ]);

            $reversalId = null;
            if ($locked->journal_entry_id) {
                $entry = \App\Models\JournalEntry::withoutGlobalScopes()->find($locked->journal_entry_id);

                if ($entry !== null && $entry->isPosted() && ! $entry->reversal()->exists()) {
                    $reversalId = app(JournalEntryService::class)->reverse($entry, $userId, "Pembatalan Stok Terbuang {$locked->write_off_number}")->id;
                }
            }

            $locked->update([
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancelled_reason' => $reason,
                'reversal_journal_entry_id' => $reversalId,
            ]);

            return StockWriteOff::withoutGlobalScopes()->findOrFail($locked->id);
        });
    }
}
