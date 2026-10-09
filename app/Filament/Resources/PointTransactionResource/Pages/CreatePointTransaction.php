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
        // Gap ditutup 2026-09-26 (audit Riwayat Poin Customer) -- jejak
        // "siapa" staf yang melakukan adjustment manual, terstruktur
        // (bukan cuma description bebas teks).
        $data['created_by'] = auth()->id();

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
        $record = DB::transaction(function () use ($data) {
            $customer = Customer::where('id', $data['customer_id'])->lockForUpdate()->first();

            if (! $customer) {
                Notification::make()->title('Customer tidak ditemukan atau akunnya sudah dihapus.')->danger()->send();

                $this->halt();
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

        // Push notifikasi ditambahkan 2026-09-26 (audit Riwayat Poin Customer) -- SEBELUMNYA adjustment manual admin tidak
        // memberi tahu customer sama sekali. Dikirim SETELAH transaksi selesai dan dibungkus try/catch: kegagalan push
        // tidak boleh membatalkan entri poin yang sudah sah.
        try {
            app(\App\Services\PushNotificationService::class)->sendToCustomer(
                (int) $data['customer_id'],
                $data['type'] === 'earn' ? 'Poin Bertambah' : 'Poin Berkurang',
                $data['type'] === 'earn'
                    ? "Anda mendapat {$data['points']} poin: {$data['description']}"
                    : "{$data['points']} poin Anda dikurangi: {$data['description']}"
            );
        } catch (\Throwable $e) {
            report($e);
        }

        return $record;
    }
}
