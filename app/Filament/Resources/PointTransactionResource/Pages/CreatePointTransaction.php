<?php

namespace App\Filament\Resources\PointTransactionResource\Pages;

use App\Filament\Resources\PointTransactionResource;
use App\Models\Customer;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreatePointTransaction extends CreateRecord
{
    protected static string $resource = PointTransactionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['reference_type'] = 'manual';
        $data['reference_id'] = null;

        return $data;
    }

    /**
     * Sama pola dengan CreatePartnerPointTransaction — lock baris customer,
     * cegah saldo minus untuk 'spend', lalu update loyalty_points di
     * transaksi yang sama supaya ledger (point_transactions) dan saldo
     * (customers.loyalty_points) selalu konsisten.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $customer = Customer::where('id', $data['customer_id'])->lockForUpdate()->first();

            if (! $customer) {
                throw new RuntimeException('Customer tidak ditemukan.');
            }

            if ($data['type'] === 'spend' && $customer->loyalty_points < $data['points']) {
                Notification::make()
                    ->title('Saldo poin tidak cukup')
                    ->body("Saldo poin {$customer->name} saat ini {$customer->loyalty_points}, tidak cukup untuk dikurangi {$data['points']}.")
                    ->danger()
                    ->send();

                $this->halt();
            }

            $record = static::getModel()::create($data);

            if ($data['type'] === 'earn') {
                $customer->increment('loyalty_points', $data['points']);
            } else {
                $customer->decrement('loyalty_points', $data['points']);
            }

            return $record;
        });
    }
}
