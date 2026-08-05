<?php

namespace App\Policies;

use App\Filament\Resources\NewsResource;
use App\Models\News;
use App\Models\User;

class NewsPolicy
{
    /**
     * News = konten company-wide (bukan per-toko), tapi tetap tunduk ke
     * "Akses Menu" per-user (menu_access) seperti resource lain — supaya
     * staff/divisi yang memang butuh (mis. Marketing Manager) bisa dikasih
     * akses tanpa perlu jadi super_admin/direksi.
     */
    public function viewAny(User $user): bool
    {
        return $user->canAccessStaffArea()
            && $user->hasMenuAccess(NewsResource::class);
    }

    public function view(User $user, News $news): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, News $news): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, News $news): bool
    {
        return $user->isFullAccess();
    }
}
