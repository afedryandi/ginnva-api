<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Penerimaan barang dari Permohonan Pembelian yang sudah disetujui ("Tandai Terpenuhi" di panel dan "Terima Barang" di mobile):
 * status jadi terpenuhi, stok masuk atau aset terdaftar (satu per unit), dan bila pembukuan internal aktif, jurnal + tagihan.
 * Satu pintu supaya panel dan mobile tidak punya dua versi aturan yang bisa berbeda.
 */
class PurchaseRequestReceiveService
{
    /**
     * @param  array  $data  quantity_received, actual_cost?, supplier_id?, due_date?, chart_of_account_id?, record_stock?, expiry_date?, register_asset?
     * @param  bool  $stockAllowed  pemanggil sudah memastikan akun ini boleh mengubah stok barang itu
     * @param  bool  $assetAllowed  pemanggil sudah memastikan akun ini boleh menambah aset
     *
     * @throws RuntimeException bila permohonan sudah diproses
     */
    public function receive(PurchaseRequest $record, array $data, User $user, bool $stockAllowed, bool $assetAllowed): PurchaseRequest
    {
        DB::transaction(function () use ($record, $data, $user, $stockAllowed, $assetAllowed) {
            // Kunci baris + cek ulang status di dalam transaksi (audit Hutang
            // Usaha 2026-09-29): $record dari Livewire bisa basi, klik ganda /
            // 2 orang bersamaan sebelumnya bisa bikin jurnal & Payable dobel.
            $locked = PurchaseRequest::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'approved') {
                throw new RuntimeException('Permohonan ini sudah diproses (status: ' . $locked->status . ').');
            }

            $ledger = (bool) config('features.finance');
            $supplier = ! empty($data['supplier_id']) ? \App\Models\Supplier::findOrFail($data['supplier_id']) : null;

            $locked->update([
                'status'       => 'fulfilled',
                'fulfilled_at' => now(),
                'quantity_received' => $data['quantity_received'],
                'actual_cost'  => $data['actual_cost'] ?? null,
            ]);

            // Stok masuk sejumlah yang diterima, dalam transaksi yang sama: status terpenuhi dan stok selalu sejalan.
            if (! empty($data['record_stock']) && $this->stockRecordable($locked, $stockAllowed)) {
                $item = $locked->resolveItem();
                $received = (float) $data['quantity_received'];
                $unitCost = ! empty($data['actual_cost']) && $received > 0 ? round((float) $data['actual_cost'] / $received, 2) : null;
                $note = "Permohonan {$locked->request_number} — barang diterima";

                if ($item instanceof \App\Models\RawMaterial) {
                    $item->recordMovement('in', $received, $user->id, $note, null, ! empty($data['expiry_date']) ? (string) $data['expiry_date'] : null, $unitCost, $locked->store_id);
                } else {
                    $item->recordMovement('in', $received, $user->id, $note, $unitCost, $locked->store_id);
                }

                $locked->update(['stock_recorded_at' => now()]);
            }

            if (! empty($data['register_asset']) && $this->assetRegistrable($locked, $assetAllowed)) {
                $units = max(1, min(50, (int) floor((float) $data['quantity_received'])));
                $unitCost = ! empty($data['actual_cost']) ? round((float) $data['actual_cost'] / $units, 2) : null;

                for ($i = 1; $i <= $units; $i++) {
                    \App\Models\Asset::create([
                        'asset_tag' => \App\Models\Asset::generateAssetTag(),
                        'name' => $units > 1 ? "{$locked->item_name} #{$i}" : $locked->item_name,
                        'status' => 'aktif',
                        'store_id' => $locked->store_id,
                        'received_date' => today()->toDateString(),
                        'purchase_date' => today()->toDateString(),
                        'purchase_cost' => $unitCost,
                        'notes' => "Dari permohonan pembelian {$locked->request_number}",
                        'created_by' => $user->id,
                    ]);
                }

                $locked->update(['stock_recorded_at' => now()]);
            }

            // Pembukuan dipegang Accurate 5 (FEATURE_FINANCE mati): cukup tandai terpenuhi, tanpa jurnal dan tagihan.
            if (! $ledger) {
                return;
            }

            $entry = app(PurchaseRequestPostingService::class)->post(
                $locked->refresh(),
                (float) $data['actual_cost'],
                $data['chart_of_account_id'] ?? null
            );

            $locked->update(['journal_entry_id' => $entry->id]);

            // Payable dicatat menautkan ke jurnal yang
            // BARU SAJA dibuat di atas — TIDAK bikin
            // jurnal baru lagi (lihat komentar
            // PayableService::create() vs
            // createWithJournal()), supaya saldo 2110
            // tidak dobel tercatat. source_key unik =
            // lapisan kedua anti-duplikat.
            app(PayableService::class)->create([
                'supplier_name' => $supplier->name,
                'supplier_id' => $supplier->id,
                'store_id' => $locked->store_id,
                'source_type' => 'purchase_request',
                'source_id' => $locked->id,
                'amount' => $data['actual_cost'],
                'due_date' => $data['due_date'] ?? null,
                'journal_entry_id' => $entry->id,
                'created_by' => $user->id,
            ]);
        });

        $record->refresh();

        if ($record->requested_by) {
            app(StaffNotifier::class)->toUsers(
                [$record->requested_by],
                'Permohonan Pembelian Terpenuhi',
                "Permohonan {$record->request_number} ({$record->item_name}) sudah terpenuhi, barang sudah tersedia."
            );
        }

        return $record;
    }

    /** Barang katalog masih ada, stoknya belum pernah dicatat dari permohonan ini, dan pemanggil mengizinkan. */
    public function stockRecordable(PurchaseRequest $request, bool $allowed): bool
    {
        return $allowed
            && $request->stock_recorded_at === null
            && in_array($request->item_type, ['raw_material', 'consumable_item'], true)
            && $request->resolveItem() !== null;
    }

    public function assetRegistrable(PurchaseRequest $request, bool $allowed): bool
    {
        return $allowed && $request->item_type === 'asset' && $request->stock_recorded_at === null;
    }
}
