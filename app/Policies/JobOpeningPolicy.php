<?php

namespace App\Policies;

use App\Filament\Resources\JobOpeningResource;
use App\Models\JobOpening;
use App\Models\User;

class JobOpeningPolicy
{
    /**
     * Lowongan kerja = konten company-wide (bukan per-toko), tapi tetap
     * tunduk ke "Akses Menu" per-user (menu_access) seperti resource lain.
     */
    public function viewAny(User $user): bool
    {
        return $user->canAccessStaffArea()
            && $user->hasMenuAccess(JobOpeningResource::class);
    }

    public function view(User $user, JobOpening $jobOpening): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, JobOpening $jobOpening): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, JobOpening $jobOpening): bool
    {
        return $user->isFullAccess();
    }
}
