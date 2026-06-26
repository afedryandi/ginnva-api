<?php

namespace App\Policies;

use App\Models\News;
use App\Models\User;

class NewsPolicy
{
    /**
     * News = konten company-wide, jadi regional_admin (admin toko) TIDAK
     * punya akses sama sekali — resource ini tidak akan muncul di sidebar
     * mereka.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function view(User $user, News $news): bool
    {
        return $user->hasRole('super_admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    public function update(User $user, News $news): bool
    {
        return $user->hasRole('super_admin');
    }

    public function delete(User $user, News $news): bool
    {
        return $user->hasRole('super_admin');
    }
}
