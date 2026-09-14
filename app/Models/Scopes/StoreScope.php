<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Scope store-id — diminta 2026-09-14 (audit framework, item
 * "Isolasi data multi-tenant"). Menutup pola bug store-scoping bocor
 * yang ditemukan berulang (8+ instance) sepanjang audit: query LANGSUNG
 * ke model (widget/report/service) yang lupa filter store_id secara
 * manual. Dengan scope ini, filter otomatis menempel di SEMUA query
 * model yang pakai trait HasStoreScope — tidak bisa "lupa" lagi.
 *
 * ATURAN (sama persis pola manual yang sudah dipakai konsisten di
 * seluruh Resource — lihat Booking/MaterialMemo/PurchaseRequest/
 * Attendance/LeaveRequest/WarningLetterResource::getEloquentQuery()):
 * - Tidak ada user login (console command/job/tinker) → TIDAK difilter
 *   sama sekali. Command terjadwal (SendServiceReminders dkk) yang
 *   memang butuh lintas-toko tidak boleh diam-diam kehilangan data.
 * - User full-access (super_admin/direksi) → TIDAK difilter. Selector
 *   toko manual (mis. dropdown "Cabang" di laporan) tetap berfungsi
 *   normal di atas ini, scope cuma no-op untuk mereka.
 * - User lain (store_manager, installer, dst) → WAJIB store_id sama
 *   dengan store_id akunnya sendiri.
 *
 * HANYA dipasang di model yang scoping-nya SELALU ketat tanpa
 * pengecualian (tidak ada baris dengan store_id NULL yang sengaja
 * harus tetap terlihat/tersembunyi berbeda dari aturan di atas) — lihat
 * catatan lengkap di memory feedback_gate_authorization_sweep.md soal
 * model mana yang PUNYA pengecualian (Asset, ScrollCode) dan sengaja
 * BELUM dipasangi scope ini.
 */
class StoreScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (! $user || ($user->isFullAccess() ?? false)) {
            return;
        }

        $builder->where($model->getTable() . '.store_id', $user->store_id);
    }
}
