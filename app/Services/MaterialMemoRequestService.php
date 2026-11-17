<?php

namespace App\Services;

use App\Filament\Resources\ConsumableItemResource;
use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\RawMaterialResource;
use App\Models\ConsumableItem;
use App\Models\InventoryItem;
use App\Models\MaterialMemo;
use App\Models\MaterialMemoItem;
use App\Models\RawMaterial;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Memo barang berpersetujuan (2026-11-17).
 *
 *   1. Installer / Installer Leader mengajukan "mau ambil barang" (kendaraan, SPK/booking, catatan), TANPA daftar barang dan jumlah:
 *      jumlah bahan dan meter gulungan belum tentu tetap, dan sering ada tambahan.
 *   2. Store manager toko itu (atau akses penuh) menyetujui atau menolak (alasan wajib bila ditolak).
 *   3. Staff gudang (akun yang punya menu Bahan Baku / Produk PPF-WF / Barang Habis Pakai) mencatat barang dan jumlah yang benar-benar
 *      diambil. STOK BERKURANG DI SINI, lewat MaterialMemoStockService yang sama dengan alur langsung. Gulungan PPF/WF dicatat tanpa meter
 *      (sisa gulungan belum berkurang); meternya diisi belakangan setelah pekerjaan (setMeters).
 *   4. Gudang menekan "Selesai diserahkan": memo TERKUNCI (barang dan jumlahnya tidak bisa diubah lagi, bahkan oleh akun berizin Ubah;
 *      hanya akses penuh yang bisa membuka) dan direksi diberi tahu. Pengembalian bahan dan pengisian meter tetap bisa dicatat.
 *   5. Minta tambahan: installer mengajukan lagi dengan catatan (resubmit), diputuskan dengan alur yang sama; memo terbuka kembali untuk
 *      gudang saat disetujui dan terkunci lagi saat selesai diserahkan.
 *
 * Akses penuh tetap memakai alur langsung (flow 'direct'); memo lama tidak berubah.
 */
class MaterialMemoRequestService
{
    private const ITEM_TYPES = ['raw_material', 'consumable_item', 'inventory_item'];

    // ------------------------------------------------------------- aturan siapa boleh apa

    public function canApprove(User $user, MaterialMemo $memo): bool
    {
        if ((int) $memo->created_by === (int) $user->id) {
            return false;
        }

        return $user->isFullAccess() || ($user->isStoreManager() && (int) $user->store_id === (int) $memo->store_id);
    }

    public function hasStockAccess(User $user, string $type): bool
    {
        return match ($type) {
            'raw_material' => $user->hasMaterialAccess() && $user->hasModuleAction(RawMaterialResource::class, 'update', false),
            'consumable_item' => $user->hasConsumableAccess() && $user->hasModuleAction(ConsumableItemResource::class, 'update', false),
            'inventory_item' => $user->hasPpfWfAccess() && $user->hasModuleAction(InventoryItemResource::class, 'update', false),
            default => false,
        };
    }

    /** Gudang = akun yang bisa mengubah stok minimal satu jenis barang (atau akses penuh), dan bukan pengaju memo. */
    public function canHandOver(User $user, MaterialMemo $memo): bool
    {
        if ((int) $memo->created_by === (int) $user->id) {
            return false;
        }

        if ($user->isFullAccess()) {
            return true;
        }

        foreach (self::ITEM_TYPES as $type) {
            if ($this->hasStockAccess($user, $type)) {
                return true;
            }
        }

        return false;
    }

    private function warehouseUsers(MaterialMemo $memo)
    {
        return User::query()->where('is_active', true)->get()
            ->filter(fn (User $u) => ! $u->isFullAccess() && $this->canHandOver($u, $memo));
    }

    // ------------------------------------------------------------- pengajuan

    /** Memo baru dari non-akses-penuh: langsung menunggu persetujuan. */
    public function submitNew(MaterialMemo $memo, ?string $note = null): MaterialMemo
    {
        $memo->update([
            'flow' => MaterialMemo::FLOW_REQUEST,
            'request_status' => MaterialMemo::REQ_PENDING,
            'request_note' => ($note !== null && trim($note) !== '') ? trim($note) : null,
            'submitted_at' => now(),
        ]);

        $this->notifySubmitted($memo);

        return $memo;
    }

    /** Untuk memo yang baru dibuat langsung dalam status menunggu (dibuat oleh pemanggil): beri tahu penyetuju. */
    public function notifyNewRequest(MaterialMemo $memo): void
    {
        $this->notifySubmitted($memo);
    }

    /** Pengajuan ulang setelah ditolak, atau minta tambahan setelah selesai diserahkan. */
    public function resubmit(MaterialMemo $memo, User $by, ?string $note = null): MaterialMemo
    {
        $locked = DB::transaction(function () use ($memo, $by, $note) {
            $locked = MaterialMemo::withoutGlobalScopes()->whereKey($memo->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isRequestFlow()) {
                throw new InvalidArgumentException('Memo ini memakai alur langsung, bukan pengajuan.');
            }

            if ((int) $locked->created_by !== (int) $by->id && ! $by->isFullAccess()) {
                throw new InvalidArgumentException('Hanya pengaju memo ini yang bisa mengajukan ulang.');
            }

            if (! in_array($locked->request_status, [MaterialMemo::REQ_REJECTED, MaterialMemo::REQ_FULFILLED], true)) {
                throw new InvalidArgumentException('Memo ini masih diproses, belum bisa diajukan ulang.');
            }

            if ($locked->request_status === MaterialMemo::REQ_FULFILLED && ($note === null || trim($note) === '')) {
                throw new InvalidArgumentException('Isi catatan: apa yang perlu ditambah.');
            }

            if ($locked->booking_id && $locked->isBookingLocked()) {
                throw new InvalidArgumentException('Booking-nya sudah selesai, jadi memo ini tidak bisa meminta tambahan.');
            }

            $locked->update([
                'request_status' => MaterialMemo::REQ_PENDING,
                'request_note' => ($note !== null && trim($note) !== '') ? trim($note) : $locked->request_note,
                'submitted_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'reject_reason' => null,
            ]);

            return $locked;
        });

        $this->notifySubmitted($locked);

        return $locked;
    }

    public function approve(MaterialMemo $memo, User $by): MaterialMemo
    {
        $locked = DB::transaction(function () use ($memo, $by) {
            $locked = MaterialMemo::withoutGlobalScopes()->whereKey($memo->id)->lockForUpdate()->firstOrFail();

            if ($locked->request_status !== MaterialMemo::REQ_PENDING) {
                throw new InvalidArgumentException('Memo ini tidak sedang menunggu persetujuan.');
            }

            if (! $this->canApprove($by, $locked)) {
                throw new InvalidArgumentException('Memo ini hanya bisa disetujui store manager tokonya atau akses penuh, dan bukan oleh pengajunya sendiri.');
            }

            $locked->update(['request_status' => MaterialMemo::REQ_APPROVED, 'approved_by' => $by->id, 'approved_at' => now()]);

            return $locked;
        });

        $this->notifyApproved($locked, $by);

        return $locked;
    }

    public function reject(MaterialMemo $memo, User $by, string $reason): MaterialMemo
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Isi alasan penolakan.');
        }

        $locked = DB::transaction(function () use ($memo, $by, $reason) {
            $locked = MaterialMemo::withoutGlobalScopes()->whereKey($memo->id)->lockForUpdate()->firstOrFail();

            if ($locked->request_status !== MaterialMemo::REQ_PENDING) {
                throw new InvalidArgumentException('Memo ini tidak sedang menunggu persetujuan.');
            }

            if (! $this->canApprove($by, $locked)) {
                throw new InvalidArgumentException('Memo ini hanya bisa diputuskan store manager tokonya atau akses penuh, dan bukan oleh pengajunya sendiri.');
            }

            $locked->update(['request_status' => MaterialMemo::REQ_REJECTED, 'rejected_by' => $by->id, 'rejected_at' => now(), 'reject_reason' => trim($reason)]);

            return $locked;
        });

        $this->notifyRejected($locked, $by);

        return $locked;
    }

    // ------------------------------------------------------------- penyerahan oleh gudang

    /** Memo harus berstatus disetujui dan penyerahnya berhak; dikunci agar dua orang tidak mencatat bersamaan dengan status yang basi. */
    private function lockForHandover(MaterialMemo $memo, User $by): MaterialMemo
    {
        $locked = MaterialMemo::withoutGlobalScopes()->whereKey($memo->id)->lockForUpdate()->firstOrFail();

        if ($locked->request_status !== MaterialMemo::REQ_APPROVED) {
            throw new InvalidArgumentException($locked->isHandoverLocked()
                ? 'Memo ini sudah selesai diserahkan dan terkunci.'
                : 'Barang hanya bisa dicatat setelah memo disetujui.');
        }

        if (! $this->canHandOver($by, $locked)) {
            throw new InvalidArgumentException('Hanya akun yang punya menu barangnya (dan bukan pengaju memo) yang bisa mencatat penyerahan.');
        }

        return $locked;
    }

    /**
     * Catat barang yang diserahkan. Bahan: $qty wajib (> 0). Gulungan PPF/WF: $qty diabaikan, dicatat tanpa meter.
     *
     * @throws InvalidArgumentException
     */
    public function addItem(MaterialMemo $memo, User $by, string $type, int $itemId, ?float $qty, ?string $note): MaterialMemoItem
    {
        if (! in_array($type, \App\Support\HiddenInventoryTypes::allowed(self::ITEM_TYPES), true)) {
            throw new InvalidArgumentException('Jenis barang ini tidak bisa dicatat.');
        }

        if (! $by->isFullAccess() && ! $this->hasStockAccess($by, $type)) {
            throw new InvalidArgumentException('Akun ini tidak punya izin Ubah pada menu barang tersebut.');
        }

        if ($type !== 'inventory_item' && ($qty === null || $qty <= 0)) {
            throw new InvalidArgumentException('Jumlah yang diambil harus lebih besar dari 0.');
        }

        return DB::transaction(function () use ($memo, $by, $type, $itemId, $qty, $note) {
            $locked = $this->lockForHandover($memo, $by);

            return match ($type) {
                'raw_material', 'consumable_item' => MaterialMemoStockService::addMaterial(
                    $this->material($type, $itemId), $type, $locked, (float) $qty, $by->id, $note
                ),
                'inventory_item' => MaterialMemoStockService::addInventory(
                    InventoryItem::find($itemId) ?? throw new InvalidArgumentException('Barang tidak ditemukan.'), $locked, null, $by->id, $note
                ),
            };
        });
    }

    private function material(string $type, int $id): RawMaterial|ConsumableItem
    {
        $item = $type === 'raw_material' ? RawMaterial::find($id) : ConsumableItem::find($id);

        return $item ?? throw new InvalidArgumentException('Barang tidak ditemukan.');
    }

    /** Ubah jumlah bahan yang sudah dicatat (selama belum Selesai diserahkan). */
    public function updateItemQty(MaterialMemo $memo, MaterialMemoItem $item, User $by, float $qty): void
    {
        DB::transaction(function () use ($memo, $item, $by, $qty) {
            $locked = $this->lockForHandover($memo, $by);

            if ((int) $item->material_memo_id !== (int) $locked->id || ! in_array($item->item_type, ['raw_material', 'consumable_item'], true)) {
                throw new InvalidArgumentException('Baris ini tidak bisa diubah jumlahnya.');
            }

            MaterialMemoStockService::updateMaterialQty($item->refresh(), $qty, $by->id, $locked);
        });
    }

    public function removeItem(MaterialMemo $memo, MaterialMemoItem $item, User $by): void
    {
        DB::transaction(function () use ($memo, $item, $by) {
            $locked = $this->lockForHandover($memo, $by);

            if ((int) $item->material_memo_id !== (int) $locked->id) {
                throw new InvalidArgumentException('Baris ini bukan milik memo ini.');
            }

            MaterialMemoStockService::reverseItem($item, $by->id, $locked);
            $item->delete();
        });
    }

    /**
     * Catat pengembalian bahan sisa oleh gudang. Boleh setelah memo disetujui, juga setelah terkunci penyerahan (mencatat fakta baru);
     * hanya kunci booking Selesai yang menahannya. Koreksi pengembalian tetap terkunci.
     *
     * @throws InvalidArgumentException
     */
    public function recordReturn(MaterialMemo $memo, MaterialMemoItem $item, User $by, float $qtyReturned): void
    {
        if (! in_array($memo->request_status, [MaterialMemo::REQ_APPROVED, MaterialMemo::REQ_FULFILLED], true)) {
            throw new InvalidArgumentException('Pengembalian bisa dicatat setelah memo disetujui.');
        }

        if ($memo->isBookingLocked()) {
            throw new InvalidArgumentException('Booking-nya sudah selesai, jadi memo ini terkunci.');
        }

        if (! $this->canHandOver($by, $memo) || (! $by->isFullAccess() && ! $this->hasStockAccess($by, $item->item_type))) {
            throw new InvalidArgumentException('Pengembalian dicatat oleh gudang (pemegang menu barangnya), bukan pengaju memo.');
        }

        if ((int) $item->material_memo_id !== (int) $memo->id) {
            throw new InvalidArgumentException('Baris ini bukan milik memo ini.');
        }

        MaterialMemoStockService::returnMaterial($item, $qtyReturned, $by->id, $memo);
    }

    /**
     * Gudang menekan "Selesai diserahkan": minimal satu barang dicatat pada putaran ini; memo terkunci, direksi diberi tahu.
     */
    public function complete(MaterialMemo $memo, User $by): MaterialMemo
    {
        $locked = DB::transaction(function () use ($memo, $by) {
            $locked = $this->lockForHandover($memo, $by);

            $recorded = $locked->items()->where('created_at', '>=', $locked->approved_at)->exists();

            if (! $recorded) {
                throw new InvalidArgumentException('Catat minimal satu barang yang diserahkan sebelum menyelesaikan.');
            }

            // unlocked_at dikosongkan supaya kunci berlaku lagi (mis. memo yang pernah dibuka akses penuh lalu meminta tambahan).
            $locked->update(['request_status' => MaterialMemo::REQ_FULFILLED, 'fulfilled_by' => $by->id, 'fulfilled_at' => now(), 'unlocked_at' => null, 'unlocked_by' => null, 'unlock_reason' => null]);

            return $locked;
        });

        $this->notifyFulfilled($locked, $by);

        return $locked;
    }

    /**
     * Isi meter pemakaian gulungan yang diserahkan tanpa meter, setelah pekerjaan. HANYA staff gudang (akun berizin Ubah pada Produk PPF/WF,
     * bukan pengaju memo) atau akses penuh; installer tidak mengisinya. Tetap bisa saat memo terkunci (ini mencatat fakta baru, bukan
     * mengubah penyerahan).
     */
    public function setMeters(MaterialMemo $memo, MaterialMemoItem $item, User $by, float $meters): void
    {
        $allowed = $by->isFullAccess()
            || ((int) $memo->created_by !== (int) $by->id && $this->hasStockAccess($by, 'inventory_item'));

        if (! $allowed) {
            throw new InvalidArgumentException('Meter gulungan diisi oleh staff gudang (pemegang menu Produk PPF/WF), bukan pengaju memo.');
        }

        if (! in_array($memo->request_status, [MaterialMemo::REQ_APPROVED, MaterialMemo::REQ_FULFILLED], true) && $memo->isRequestFlow()) {
            throw new InvalidArgumentException('Meter bisa diisi setelah memo disetujui.');
        }

        if ((int) $item->material_memo_id !== (int) $memo->id) {
            throw new InvalidArgumentException('Baris ini bukan milik memo ini.');
        }

        MaterialMemoStockService::recordPendingMeters($item, $meters, $by->id, $memo);
    }

    // ------------------------------------------------------------- pemberitahuan

    private function summary(MaterialMemo $memo): string
    {
        return collect([$memo->memo_number, $memo->vehicle_info, $memo->spk_number ? "SPK {$memo->spk_number}" : null, $memo->request_note])
            ->filter()->implode(' · ');
    }

    private function notifySubmitted(MaterialMemo $memo): void
    {
        try {
            $memo->loadMissing(['creator:id,name', 'store:id,name']);
            $ids = User::query()->where('is_active', true)->get()
                ->filter(fn (User $u) => $this->canApprove($u, $memo))->pluck('id')->values();

            app(StaffNotifier::class)->toUsers($ids, 'Memo Barang Menunggu Persetujuan',
                ($memo->creator?->name ?? 'Staff') . ' mengajukan pengambilan barang' . ($memo->store ? " untuk {$memo->store->name}" : '') . '. ' . $this->summary($memo), color: 'warning');

            app(ApprovalMailer::class)->requested('Memo Barang', $memo->creator?->name ?? 'Staff', $this->summary($memo), $memo->store?->name, $ids);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notifyApproved(MaterialMemo $memo, User $by): void
    {
        try {
            app(StaffNotifier::class)->toUsers([$memo->created_by], 'Memo Barang Disetujui', "{$by->name} menyetujui memo {$memo->memo_number}. Ambil barangnya di gudang.", color: 'success');
            app(StaffNotifier::class)->toUsers($this->warehouseUsers($memo)->pluck('id'), 'Memo Barang Siap Diserahkan',
                'Memo disetujui, catat barang yang diambil. ' . $this->summary($memo), color: 'warning');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notifyRejected(MaterialMemo $memo, User $by): void
    {
        try {
            app(StaffNotifier::class)->toUsers([$memo->created_by], 'Memo Barang Ditolak', "{$by->name} menolak memo {$memo->memo_number}: {$memo->reject_reason}", color: 'danger');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Langkah terakhir: pengaju diberi tahu, direksi (akses penuh) diberi tahu saja. */
    private function notifyFulfilled(MaterialMemo $memo, User $by): void
    {
        try {
            $memo->loadMissing(['creator:id,name', 'store:id,name']);
            $count = $memo->items()->where('created_at', '>=', $memo->approved_at)->count();

            app(StaffNotifier::class)->toUsers([$memo->created_by], 'Barang Memo Sudah Diserahkan', "{$by->name} menyerahkan {$count} barang untuk memo {$memo->memo_number}.", color: 'success');

            $directors = User::query()->where('is_active', true)->get()
                ->filter(fn (User $u) => $u->isFullAccess() && (int) $u->id !== (int) $by->id)->pluck('id');

            app(StaffNotifier::class)->toUsers($directors, 'Memo Barang Diserahkan',
                "{$memo->memo_number}" . ($memo->store ? " · {$memo->store->name}" : '') . " · diminta {$memo->creator?->name}, diserahkan {$by->name} ({$count} barang).");
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
