<?php

namespace App\Services;

use App\Models\ConsumableItem;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestGroup;
use App\Models\RawMaterial;
use App\Models\User;
use App\Support\HiddenInventoryTypes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Pengajuan Permohonan Pembelian dengan satu atau lebih barang (2026-11-23). Satu nomor induk (PP-...) memuat beberapa baris; setiap baris
 * adalah PurchaseRequest sendiri, sehingga persetujuan, penerimaan, hutang, dan jurnal yang sudah ada tetap berjalan per baris.
 * Dipakai bersama oleh API aplikasi dan form panel supaya aturannya sama.
 */
class PurchaseRequestGroupService
{
    public const MAX_LINES = 30;

    /**
     * @param  array<int, array{item_type: string, item_id?: int|string|null, item_name?: string|null, unit?: string|null, quantity: float|int|string}>  $lines
     *
     * @throws \InvalidArgumentException pesan siap tampil ke pengguna; tidak ada yang tersimpan bila salah satu baris ditolak
     */
    public function submit(User $user, ?int $storeId, ?string $reason, array $lines): PurchaseRequestGroup
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('Tambahkan minimal satu barang.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new \InvalidArgumentException('Satu permohonan paling banyak ' . self::MAX_LINES . ' barang.');
        }

        $store = $user->isFullAccess() ? ($storeId ?? $user->store_id) : $user->store_id;
        $prepared = $this->prepare($lines);
        $reason = filled($reason) ? trim((string) $reason) : null;

        $group = DB::transaction(function () use ($user, $store, $reason, $prepared) {
            $group = PurchaseRequestGroup::open(['store_id' => $store, 'requested_by' => $user->id, 'reason' => $reason]);

            foreach ($prepared as $line) {
                $this->createLine($group, $user, $store, $reason, $line);
            }

            return $group;
        });

        return $group->load('lines');
    }

    /** @return array<int, array<string, mixed>> */
    private function prepare(array $lines): array
    {
        $allowed = HiddenInventoryTypes::allowed(['raw_material', 'consumable_item', 'asset', 'other']);
        $seen = [];
        $out = [];

        foreach (array_values($lines) as $index => $line) {
            $n = $index + 1;
            $type = (string) ($line['item_type'] ?? '');
            $quantity = $line['quantity'] ?? null;

            if (! in_array($type, $allowed, true)) {
                throw new \InvalidArgumentException("Barang {$n}: jenis barang tidak valid.");
            }

            if (! is_numeric($quantity) || (float) $quantity < 0.01 || (float) $quantity > 1000000) {
                throw new \InvalidArgumentException("Barang {$n}: jumlah harus lebih dari 0.");
            }

            $name = null;
            $unit = null;
            $itemId = null;

            if (in_array($type, ['raw_material', 'consumable_item'], true)) {
                $itemId = (int) ($line['item_id'] ?? 0);
                $catalog = $type === 'raw_material' ? RawMaterial::find($itemId) : ConsumableItem::find($itemId);

                if (! $catalog) {
                    throw new \InvalidArgumentException("Barang {$n}: " . ($type === 'raw_material' ? 'bahan baku' : 'barang habis pakai') . ' tidak ditemukan.');
                }

                if ($catalog->is_active === false) {
                    throw new \InvalidArgumentException("Barang {$n}: {$catalog->name} sudah dinonaktifkan dan tidak bisa diminta beli.");
                }

                $key = "{$type}:{$itemId}";
                if (isset($seen[$key])) {
                    throw new \InvalidArgumentException("{$catalog->name} dipilih lebih dari satu kali. Gabungkan jumlahnya dalam satu baris.");
                }
                $seen[$key] = true;

                // Satu barang satu permohonan terbuka: mencegah dua orang mengajukan barang yang sama.
                if ($open = app(RestockRequestService::class)->openRequest($catalog)) {
                    throw new \InvalidArgumentException("Sudah ada permohonan untuk {$catalog->name} ({$open->request_number}, status " . ($open->status === 'approved' ? 'disetujui' : 'menunggu') . ').');
                }

                $name = $catalog->name;
                $unit = $catalog->unit;
            } else {
                $name = trim((string) ($line['item_name'] ?? ''));
                if ($name === '') {
                    throw new \InvalidArgumentException("Barang {$n}: nama barang wajib diisi.");
                }
                if (mb_strlen($name) > 255) {
                    throw new \InvalidArgumentException("Barang {$n}: nama barang terlalu panjang.");
                }
                $unit = $type === 'other' && filled($line['unit'] ?? null) ? mb_substr(trim((string) $line['unit']), 0, 30) : null;
            }

            $out[] = ['item_type' => $type, 'item_id' => $itemId, 'item_name' => $name, 'unit' => $unit, 'quantity' => round((float) $quantity, 2)];
        }

        return $out;
    }

    private function createLine(PurchaseRequestGroup $group, User $user, ?int $store, ?string $reason, array $line): PurchaseRequest
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return PurchaseRequest::create($line + [
                    'purchase_request_group_id' => $group->id,
                    'store_id' => $store,
                    'reason' => $reason,
                    'status' => 'pending',
                    'requested_by' => $user->id,
                ]);
            } catch (QueryException $e) {
                if ($attempt >= 3 || ! str_contains($e->getMessage(), 'request_number')) {
                    throw $e;
                }
            }
        }
    }
}
