<?php

namespace App\Filament\Resources\PartnerPointTransactionResource\Pages;

use App\Filament\Resources\PartnerPointTransactionResource;
use App\Models\PartnerPointTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\ViewRecord;

/**
 * PartnerPointTransactionResource::form() dipakai Create (Select/TextInput
 * yang bisa diisi admin). Halaman detail ini SENGAJA punya form() sendiri
 * berisi Placeholder read-only dengan label yang sudah diterjemahkan (mis.
 * "Sumber" -> "Booking"/"Input Manual") — sama pola dengan
 * ViewPointTransaction (Riwayat Poin Customer), supaya detail transaksi
 * ditampilkan rapi, bukan field Select/Input mentah milik form Create.
 */
class ViewPartnerPointTransaction extends ViewRecord
{
    protected static string $resource = PartnerPointTransactionResource::class;

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Transaksi')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('partner.business_name')
                        ->label('Partner')
                        ->content(fn (?PartnerPointTransaction $record) => $record?->partner
                            ? "{$record->partner->business_name} ({$record->partner->referral_code})"
                            : '—'),

                    Forms\Components\Placeholder::make('type')
                        ->label('Tipe')
                        ->content(fn (?PartnerPointTransaction $record) => $record?->type === 'earn' ? 'Dapat Poin' : 'Pakai Poin'),

                    Forms\Components\Placeholder::make('points')
                        ->label('Jumlah Poin')
                        ->content(fn (?PartnerPointTransaction $record) => $record?->points),

                    Forms\Components\Placeholder::make('reference_type')
                        ->label('Sumber')
                        ->content(fn (?PartnerPointTransaction $record) => match ($record?->reference_type) {
                            'booking'                    => 'Booking (transaksi toko)',
                            'reward_redemption'          => 'Tukar Reward',
                            'reward_redemption_refund'   => 'Refund Pembatalan Reward',
                            'reward_redemption_reversal' => 'Pembatalan Reward Dibatalkan',
                            'manual'                     => 'Input Manual Admin',
                            default                      => $record?->reference_type ?? '—',
                        }),

                    Forms\Components\Placeholder::make('description')
                        ->label('Keterangan')
                        ->columnSpanFull()
                        ->content(fn (?PartnerPointTransaction $record) => $record?->description ?? '—'),

                    Forms\Components\Placeholder::make('created_at')
                        ->label('Tanggal')
                        ->content(fn (?PartnerPointTransaction $record) => $record?->created_at?->format('d M Y H:i')),
                ]),
        ]);
    }
}
