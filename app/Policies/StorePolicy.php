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

    /**
     * SENGAJA tidak ada pengecualian kepemilikan toko di sini (beda dari
     * view() di atas) — Store Manager cuma boleh LIHAT toko miliknya
     * sendiri, tidak boleh ubah apa pun, termasuk field HR/payroll
     * (radius absen, toleransi telat, potongan gaji) yang kalau bisa
     * diedit sendiri jadi conflict of interest. Keputusan bisnis
     * 2026-08-29 — lihat StoreResource::getEloquentQuery().
     */
    public function update(User $user, Store $store): bool
    {
        return $user->isFullAccess();
    }

    public function delete(User $user, Store $store): bool
    {
        return $user->isFullAccess();
    }
}
