<?php

namespace App\Services;

use App\Models\StockOpname;
use App\Models\User;

/**
 * Pemberitahuan draf Stok Opname baru ke akses penuh (2026-11-17): draf menunggu persetujuan (Terapkan) dan tampil di Pusat Persetujuan.
 * Kegagalan kirim tidak boleh menggagalkan penyimpanan hasil hitung.
 */
class StockOpnameNotifier
{
    public function notifyDraft(StockOpname $opname): void
    {
        try {
            $opname->loadMissing(['store:id,name', 'creator:id,name']);

            $ids = User::query()->where('is_active', true)->get()
                ->filter(fn (User $user) => $user->isFullAccess() && $user->id !== $opname->created_by)
                ->pluck('id')->values();

            if ($ids->isEmpty()) {
                return;
            }

            $total = $opname->items()->count();
            $diff = $opname->items()->where('delta', '!=', 0)->count();
            $summary = "{$opname->opname_number} · {$total} item dihitung, {$diff} berselisih";

            app(StaffNotifier::class)->toUsers(
                $ids,
                'Stok Opname Menunggu Persetujuan',
                ($opname->creator?->name ?? 'Staff') . " menyetor hasil hitung" . ($opname->store ? " untuk {$opname->store->name}" : '') . ". {$summary}. Tinjau selisihnya lalu terapkan.",
                color: 'warning',
            );

            app(ApprovalMailer::class)->requested(
                'Stok Opname',
                $opname->creator?->name ?? 'Staff',
                $summary,
                $opname->store?->name,
                $ids,
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
