<?php

namespace App\Filament\Resources\PartnerPointTransactionResource\Pages;

use App\Filament\Resources\PartnerPointTransactionResource;
use App\Models\Partner;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreatePartnerPointTransaction extends CreateRecord
{
    protected static string $resource = PartnerPointTransactionResource::class;

    /**
     * Entri manual ditandai reference_type='manual' (beda dari 'booking'/
     * 'reward_redemption' yang otomatis dari sistem) supaya gampang
     * dibedakan & difilter nanti.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['reference_type'] = 'manual';
        $data['reference_id'] = null;

        return $data;
    }

    /**
     * Override total (bukan cuma afterCreate) supaya create record +
     * update Partner::points_balance jadi SATU DB transaction dengan row
     * lock — pola sama seperti RewardRedemptionService::redeem() — jadi
     * tidak ada window balapan antara dua input manual bersamaan yang
     * bisa bikin saldo salah hitung atau (untuk 'spend') minus.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $partner = Partner::where('id', $data['partner_id'])->lockForUpdate()->first();

            if (! $partner) {
                throw new RuntimeException('Partner tidak ditemukan.');
            }

            if ($data['type'] === 'spend' && $partner->points_balance < $data['points']) {
                Notification::make()
                    ->title('Saldo poin tidak cukup')
                    ->body("Saldo poin {$partner->business_name} saat ini {$partner->points_balance}, tidak cukup untuk dikurangi {$data['points']}.")
                    ->danger()
                    ->send();

                $this->halt();
            }

            $record = static::getModel()::create($data);

            if ($data['type'] === 'earn') {
                $partner->increment('points_balance', $data['points']);
            } else {
                $partner->decrement('points_balance', $data['points']);
            }

            return $record;
        });
    }
}
