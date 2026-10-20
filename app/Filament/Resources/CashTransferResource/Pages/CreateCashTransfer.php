<?php

namespace App\Filament\Resources\CashTransferResource\Pages;

use App\Filament\Resources\CashTransferResource;
use App\Services\CashTransferService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

class CreateCashTransfer extends CreateRecord
{
    protected static string $resource = CashTransferResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CashTransferService::class)->create(
                (int) $data['from_account_id'],
                (int) $data['to_account_id'],
                (float) $data['amount'],
                Carbon::parse($data['transfer_date']),
                ! empty($data['store_id']) ? (int) $data['store_id'] : null,
                $data['notes'] ?? null,
                auth()->id(),
            );
        } catch (RuntimeException $e) {
            Notification::make()->title('Pemindahan tidak bisa disimpan')->body($e->getMessage())->danger()->send();

            $this->halt();
        }
    }
}
