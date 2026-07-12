<?php

namespace App\Policies;

use App\Models\JobOpening;
use App\Models\User;

class JobOpeningPolicy
{
    /**
     * Lowongan kerja = konten company-wide, jadi regional_admin (admin
     * toko) TIDAK punya akses — resource ini tidak muncul di sidebar
     * mereka. Sama seperti pola NewsPolicy.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function view(User $user, JobOpening $jobOpening): bool
    {
        return $user->hasRole('super_admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function update(User $user, JobOpening $jobOpening): bool
    {
        return $user->hasRole('super_admin');
    }

    public function delete(User $user, JobOpening $jobOpening): bool
    {
        return $user->hasRole('super_admin');
    }
}
