<?php

namespace App\Filament\Resources\ChartOfAccountResource\Pages;

use App\Filament\Resources\ChartOfAccountResource;
use App\Models\ChartOfAccount;
use Filament\Resources\Pages\CreateRecord;

class CreateChartOfAccount extends CreateRecord
{
    protected static string $resource = ChartOfAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // normal_balance BUKAN field form terpisah — selalu diturunkan
        // dari 'type' di sini, supaya tidak mungkin ada akun dengan
        // klasifikasi & saldo normal yang tidak konsisten.
        $data['normal_balance'] = ChartOfAccount::normalBalanceFor($data['type']);

        return $data;
    }
}
