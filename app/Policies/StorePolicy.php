<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;

class StorePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'regional_admin']);
    }

    public function view(User $user, Store $store): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Admin toko hanya boleh melihat detail toko miliknya sendiri.
        return $user->store_id === $store->id;
    }

    public function create(User $user): bool
    {
        // Buat/hapus/ubah master data toko hanya super_admin.
        return $user->hasRole('super_admin');
    }

    public function update(User $user, Store $store): bool
    {
        return $user->hasRole('super_admin');
    }

    public function delete(User $user, Store $store): bool
    {
        return $user->hasRole('super_admin');
    }
}
