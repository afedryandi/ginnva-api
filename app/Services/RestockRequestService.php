<?php

namespace App\Services;

use App\Models\ConsumableItem;
use App\Models\PurchaseRequest;
use App\Models\RawMaterial;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Permintaan beli langsung dari peringatan stok menipis (audit ACCURATE 5): membuat Permohonan Pembelian berstatus menunggu untuk
 * sebuah bahan baku atau barang habis pakai. Stok bahan tidak punya toko, jadi permintaan terbuka (menunggu atau disetujui) oleh toko mana
 * pun dihitung; satu barang tidak boleh punya dua permintaan terbuka sekaligus. Persetujuan dan pemenuhan tetap lewat alur Permohonan Pembelian.
 */
class RestockRequestService
{
    public function itemType(RawMaterial|ConsumableItem $item): string
    {
        return $item instanceof RawMaterial ? 'raw_material' : 'consumable_item';
    }

    /** Selisih ambang dan stok saat ini, minimal 1 satuan; hanya saran awal yang boleh diubah staf. */
    public function suggestedQuantity(RawMaterial|ConsumableItem $item): float
    {
        $deficit = (float) $item->reorder_point - (float) $item->current_stock;

        return round(max(1.0, $deficit), 2);
    }

    /** Permintaan beli yang masih berjalan (menunggu atau disetujui) untuk barang ini, dari toko mana pun. */
    public function openRequest(RawMaterial|ConsumableItem $item): ?PurchaseRequest
    {
        return PurchaseRequest::withoutGlobalScopes()
            ->where('item_type', $this->itemType($item))
            ->where('item_id', $item->getKey())
            ->whereIn('status', ['pending', 'approved'])
            ->orderBy('id')
            ->first();
    }

    /** @throws RuntimeException bila jumlah tidak sah atau barang sudah punya permintaan terbuka */
    public function create(RawMaterial|ConsumableItem $item, int $storeId, float $quantity, ?string $note, User $requester): PurchaseRequest
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Jumlah yang diminta harus lebih dari nol.');
        }

        return DB::transaction(function () use ($item, $storeId, $quantity, $note, $requester) {
            // Kunci baris barang dulu supaya dua klik bersamaan tidak sama-sama lolos pemeriksaan permintaan terbuka.
            $locked = $item::query()->lockForUpdate()->findOrFail($item->getKey());

            if ($open = $this->openRequest($locked)) {
                throw new RuntimeException("Sudah ada permintaan beli untuk {$locked->name} ({$open->request_number}, status " . ($open->status === 'approved' ? 'disetujui' : 'menunggu') . ').');
            }

            $unit = $locked->unit;
            $reason = trim(implode(' ', array_filter([
                'Stok menipis: ' . number_format((float) $locked->current_stock, 2, ',', '.') . " {$unit} (ambang " . number_format((float) $locked->reorder_point, 2, ',', '.') . ").",
                $locked->preferredSupplier ? "Pemasok utama: {$locked->preferredSupplier->name}." : null,
                $note ? trim($note) : null,
            ])));

            return PurchaseRequest::create([
                'store_id' => $storeId,
                'item_type' => $this->itemType($locked),
                'item_id' => $locked->getKey(),
                'item_name' => $locked->name,
                'unit' => $unit,
                'quantity' => $quantity,
                'reason' => $reason,
                'status' => 'pending',
                'requested_by' => $requester->id,
            ]);
        });
    }
}
