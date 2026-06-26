<?php

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;

class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'regional_admin']);
    }

    public function view(User $user, Quotation $quotation): bool
    {
        return $this->canAccessRecord($user, $quotation);
    }

    public function create(User $user): bool
    {
        return $user->can('quotation.manage');
    }

    public function update(User $user, Quotation $quotation): bool
    {
        return $user->can('quotation.manage') && $this->canAccessRecord($user, $quotation);
    }

    public function delete(User $user, Quotation $quotation): bool
    {
        return $user->hasRole('super_admin');
    }

    protected function canAccessRecord(User $user, Quotation $quotation): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $quotation->store_id === null || $quotation->store_id === $user->store_id;
    }
}
