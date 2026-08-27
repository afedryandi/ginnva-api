<?php

namespace App\Filament\Resources\ContractExtensionResource\Pages;

use App\Filament\Resources\ContractExtensionResource;
use App\Models\ContractExtension;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateContractExtension extends CreateRecord
{
    protected static string $resource = ContractExtensionResource::class;

    /**
     * Lewat ContractExtension::recordExtension() (bukan create() polos) —
     * itu yang urus snapshot previous_end_date DAN sinkronkan
     * users.contract_end_date sekaligus dalam 1 transaction.
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        return ContractExtension::recordExtension(
            User::findOrFail($data['user_id']),
            $data['new_end_date'],
            auth()->id(),
            $data['notes'] ?? null
        );
    }
}
