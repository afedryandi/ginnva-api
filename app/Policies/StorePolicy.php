<?php

namespace App\Policies;

use App\Filament\Resources\StoreResource;
use App\Models\Store;
use App\Models\User;

class StorePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessStaffArea()
            && $user->hasMenuAccess(StoreResource::class);
    }

    public function view(User $user, Store $store): bool
    {
        if ($user->isFullAccess()) {
            return true;
        }

        // Admin toko hanya boleh melihat detail toko miliknya sendiri.
        return $user->store_id === $store->id;
    }

    public function create(User $user): bool
    {
        // Buat/hapus/ubah master data toko hanya super_admin.
        return $user->isFullAccess();
    }

    public function update(User $user, Store $store): bool
    {
        return $user->isFullAccess();
    }

    public function delete(User $user, Store $store): bool
    {
        return $user->isFullAccess();
    }
}
