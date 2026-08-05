<?php

namespace App\Policies;

use App\Filament\Resources\WarrantyResource;
use App\Models\User;
use App\Models\Warranty;

class WarrantyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessStaffArea()
            && $user->hasMenuAccess(WarrantyResource::class);
    }

    public function view(User $user, Warranty $warranty): bool
    {
        return $this->canAccessRecord($user, $warranty);
    }

    public function create(User $user): bool
    {
        return $user->can('warranty.manage');
    }

    public function update(User $user, Warranty $warranty): bool
    {
        // Catatan QA Management: policy ini mengatur edit field biasa
        // (nama, plat nomor, tanggal, dst) lewat form EditWarranty.
        // Action Approve/Reject (review_status) di WarrantyResource &
        // ClaimsRelationManager DIATUR TERPISAH lewat ->visible() yang
        // mengecek isFullAccess() secara langsung, bukan lewat
        // policy ini — supaya admin toko tetap bisa edit detail garansi
        // miliknya tanpa otomatis bisa approve/reject.
        return $user->can('warranty.manage') && $this->canAccessRecord($user, $warranty);
    }

    public function delete(User $user, Warranty $warranty): bool
    {
        // Hapus data garansi sebaiknya hanya yang full access (super_admin/
        // direksi), untuk jaga-jaga dari kesalahan admin toko/staff.
        return $user->isFullAccess();
    }

    /**
     * super_admin/direksi (isFullAccess()): bebas akses semua data.
     * Staff lain: hanya data milik store-nya, ATAU data lama yang belum
     * punya store_id (supaya data lama tidak hilang dari tampilan siapa
     * pun sebelum di-assign manual).
     */
    protected function canAccessRecord(User $user, Warranty $warranty): bool
    {
        if ($user->isFullAccess()) {
            return true;
        }

        return $warranty->store_id === null || $warranty->store_id === $user->store_id;
    }
}