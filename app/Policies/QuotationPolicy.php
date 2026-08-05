<?php

namespace App\Policies;

use App\Filament\Resources\QuotationResource;
use App\Models\Quotation;
use App\Models\User;

class QuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessStaffArea()
            && $user->hasMenuAccess(QuotationResource::class);
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
        return $user->isFullAccess();
    }

    protected function canAccessRecord(User $user, Quotation $quotation): bool
    {
        if ($user->isFullAccess()) {
            return true;
        }

        return $quotation->store_id === null || $quotation->store_id === $user->store_id;
    }
}
