<?php

namespace App\Filament\Resources\CashCountResource\Pages;

use App\Filament\Resources\CashCountResource;
use App\Models\PaymentAccount;
use App\Services\CashCountService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

class CreateCashCount extends CreateRecord
{
    protected static string $resource = CashCountResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CashCountService::class)->record(
                PaymentAccount::with('chartAccount')->findOrFail((int) $data['payment_account_id']),
                Carbon::parse($data['count_date']),
                (float) $data['counted_amount'],
                $data['notes'] ?? null,
                auth()->id(),
            );
        } catch (RuntimeException $e) {
            Notification::make()->title('Hitungan tidak bisa disimpan')->body($e->getMessage())->danger()->send();

            $this->halt();
        }
    }
}
