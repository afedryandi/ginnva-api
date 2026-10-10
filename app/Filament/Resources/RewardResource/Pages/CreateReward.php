<?php

namespace App\Filament\Resources\RewardResource\Pages;

use App\Filament\Resources\RewardResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReward extends CreateRecord
{
    protected static string $resource = RewardResource::class;

    /** Reward barang tidak membawa data voucher (mis. sisa isian setelah pindah jenis di form). */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['type'] ?? 'item') !== 'voucher') {
            $data['voucher_discount'] = null;
            $data['voucher_valid_days'] = null;
        }

        return $data;
    }
}
