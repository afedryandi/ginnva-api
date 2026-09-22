<?php

namespace App\Services;

use App\Models\ConsumableItem;
use App\Models\MaterialMemo;
use App\Models\MaterialMemoItem;
use App\Models\RawMaterial;
use App\Models\ScrollCodeUsage;
use Illuminate\Support\Collection;

/**
 * HPP (Harga Pokok Penjualan) per booking (audit Majoo, f7: "Kolom Laba
 * Kotor per periode ... versi yang benar harus include HPP bahan baku").
 * Dipisah jadi service sendiri (bukan ditulis langsung di
 * SalesByPeriodReport) supaya bisa dipakai ulang laporan lain nanti
 * (mis. f14 "Laporan per-layanan lengkap") tanpa duplikasi.
 *
 * 2 komponen biaya:
 * - Film (PPF/Kaca Film): ScrollCodeUsage.meters × ScrollCode::costPerMeter()
 *   — dicatat LANGSUNG per booking (scroll_code_usages.booking_id),
 *   bukan lewat MaterialMemoItem, supaya tidak tergantung staff sempat
 *   input memo atau tidak.
 * - Bahan pendukung (lem, primer, dll via MaterialMemo→items): qty
 *   terpakai (qty_used, fallback qty_taken kalau belum ada pengembalian
 *   dicatat) × unit_cost RawMaterial/ConsumableItem saat ini.
 *
 * PERKIRAAN, bukan HPP akuntansi presisi: (1) unit_cost bahan pendukung
 * adalah harga RATA-RATA/TERAKHIR saat ini, bukan harga batch FIFO yang
 * sungguhan dikonsumsi saat itu (movement 'out' tidak pernah menyimpan
 * unit_cost — lihat catatan di MaterialMemoStockService); (2) gulungan
 * film yang belum diisi Harga Beli dihitung Rp 0 utk komponen itu, DAN
 * ditandai `hasMissingCost` supaya laporan bisa memperingatkan "HPP
 * belum lengkap" alih-alih diam-diam menyajikan Laba Kotor yang lebih
 * tinggi dari sebenarnya.
 */
class BookingCogsService
{
    /**
     * @param  list<int>  $bookingIds
     * @return array<int, array{cost: float, hasMissingCost: bool}>
     */
    public function forBookings(array $bookingIds): array
    {
        if (empty($bookingIds)) {
            return [];
        }

        $result = [];
        foreach ($bookingIds as $id) {
            $result[$id] = ['cost' => 0.0, 'hasMissingCost' => false];
        }

        // --- Film (ScrollCodeUsage → ScrollCode.costPerMeter()) ---
        $usages = ScrollCodeUsage::query()
            ->whereIn('booking_id', $bookingIds)
            ->with('scrollCode:id,total_length_meters,purchase_cost')
            ->get(['id', 'booking_id', 'scroll_code_id', 'meters']);

        foreach ($usages as $usage) {
            $costPerMeter = $usage->scrollCode?->costPerMeter();

            if ($costPerMeter === null) {
                $result[$usage->booking_id]['hasMissingCost'] = true;

                continue;
            }

            $result[$usage->booking_id]['cost'] += (float) $usage->meters * $costPerMeter;
        }

        // --- Bahan pendukung (MaterialMemo → items raw_material/consumable_item) ---
        $memos = MaterialMemo::query()
            ->whereIn('booking_id', $bookingIds)
            ->with(['items' => fn ($q) => $q->whereIn('item_type', ['raw_material', 'consumable_item'])])
            ->get(['id', 'booking_id']);

        $rawMaterialIds = [];
        $consumableIds = [];
        foreach ($memos as $memo) {
            foreach ($memo->items as $item) {
                if ($item->item_type === 'raw_material') {
                    $rawMaterialIds[] = $item->item_id;
                } else {
                    $consumableIds[] = $item->item_id;
                }
            }
        }

        $rawMaterialCosts = $rawMaterialIds
            ? RawMaterial::whereIn('id', array_unique($rawMaterialIds))->pluck('unit_cost', 'id')
            : collect();
        $consumableCosts = $consumableIds
            ? ConsumableItem::whereIn('id', array_unique($consumableIds))->pluck('unit_cost', 'id')
            : collect();

        foreach ($memos as $memo) {
            if (! isset($result[$memo->booking_id])) {
                continue;
            }

            /** @var MaterialMemoItem $item */
            foreach ($memo->items as $item) {
                $effectiveQty = (float) ($item->qty_used ?? $item->qty_taken ?? 0);
                if ($effectiveQty <= 0) {
                    continue;
                }

                $unitCost = $item->item_type === 'raw_material'
                    ? $rawMaterialCosts[$item->item_id] ?? null
                    : $consumableCosts[$item->item_id] ?? null;

                if ($unitCost === null) {
                    $result[$memo->booking_id]['hasMissingCost'] = true;

                    continue;
                }

                $result[$memo->booking_id]['cost'] += $effectiveQty * (float) $unitCost;
            }
        }

        return $result;
    }
}
