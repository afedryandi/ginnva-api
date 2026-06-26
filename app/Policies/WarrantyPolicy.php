<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warranty;

class WarrantyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'regional_admin']);
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
        return $user->can('warranty.manage') && $this->canAccessRecord($user, $warranty);
    }

    public function delete(User $user, Warranty $warranty): bool
    {
        // Hapus data garansi sebaiknya hanya super_admin, untuk jaga-jaga
        // dari kesalahan admin toko.
        return $user->hasRole('super_admin');
    }

    /**
     * super_admin: bebas akses semua data.
     * regional_admin (admin toko): hanya data milik store-nya, ATAU data
     * lama yang belum punya store_id (supaya data lama tidak hilang dari
     * tampilan siapa pun sebelum di-assign manual).
     */
    protected function canAccessRecord(User $user, Warranty $warranty): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $warranty->store_id === null || $warranty->store_id === $user->store_id;
    }
}
