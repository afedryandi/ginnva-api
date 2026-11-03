<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Pemberitahuan untuk penyetuju Permohonan Pembelian (akses penuh): permohonan baru dari panel, mobile, atau tombol Minta Beli, dan
 * pengingat harian untuk yang sudah menunggu lebih dari 1 hari. Sebelumnya hanya pemohon yang diberi tahu (setuju/tolak/terpenuhi),
 * sehingga permohonan bisa menunggu tanpa ada yang tahu selain angka di menu panel.
 */
class PurchaseRequestNotifier
{
    /** @return Collection<int, int> id akun akses penuh yang aktif */
    public function approverIds(): Collection
    {
        return User::query()->where('is_active', true)->get()
            ->filter(fn (User $user) => $user->isFullAccess())
            ->pluck('id');
    }

    public function notifyNew(PurchaseRequest $request): void
    {
        try {
            $request->loadMissing(['requester:id,name', 'store:id,name']);
            $ids = $this->approverIds()->reject(fn (int $id) => $id === $request->requested_by)->values();

            if ($ids->isEmpty()) {
                return;
            }

            $qty = rtrim(rtrim(number_format((float) $request->quantity, 2, ',', '.'), '0'), ',');

            app(StaffNotifier::class)->toUsers(
                $ids,
                'Permohonan Pembelian Baru',
                ($request->requester?->name ?? 'Staff') . " mengajukan {$request->item_name} ({$qty}" . ($request->unit ? " {$request->unit}" : '') . ')'
                    . ($request->store ? " untuk {$request->store->name}" : '') . ". Nomor {$request->request_number}, menunggu persetujuan."
            );
        } catch (\Throwable $e) {
            // Pemberitahuan tidak boleh menggagalkan pengajuan.
            report($e);
        }
    }

    /**
     * Ingatkan penyetuju untuk permohonan yang menunggu lebih dari 1 hari; satu permohonan paling sering sekali per hari.
     *
     * @return int jumlah permohonan yang diingatkan
     */
    public function remindPending(): int
    {
        $due = PurchaseRequest::withoutGlobalScopes()
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subDay())
            ->where(fn ($q) => $q->whereNull('last_reminded_at')->orWhere('last_reminded_at', '<=', now()->subHours(23)))
            ->orderBy('created_at')
            ->get();

        if ($due->isEmpty()) {
            return 0;
        }

        $ids = $this->approverIds();

        if ($ids->isNotEmpty()) {
            $list = $due->take(5)->map(fn (PurchaseRequest $r) => "{$r->request_number} ({$r->item_name})")->implode(', ');
            $more = $due->count() > 5 ? ' dan ' . ($due->count() - 5) . ' lainnya' : '';

            app(StaffNotifier::class)->toUsers(
                $ids,
                'Permohonan Pembelian Menunggu',
                "{$due->count()} permohonan pembelian menunggu persetujuan lebih dari 1 hari: {$list}{$more}."
            );
        }

        PurchaseRequest::withoutGlobalScopes()->whereIn('id', $due->pluck('id'))->update(['last_reminded_at' => now()]);

        return $due->count();
    }
}
