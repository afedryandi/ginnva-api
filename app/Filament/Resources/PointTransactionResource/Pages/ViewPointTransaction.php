<?php

namespace App\Filament\Resources\PointTransactionResource\Pages;

use App\Filament\Resources\PointTransactionResource;
use App\Models\PointTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\ViewRecord;

/**
 * PointTransactionResource::form() dipakai bareng oleh halaman Create
 * (Select/TextInput yang bisa diisi admin). Halaman detail riwayat ini
 * SENGAJA punya form() sendiri berisi Placeholder read-only dengan
 * label yang sudah diterjemahkan (mis. "Sumber" -> "Booking"/"Entri
 * Manual Admin") — supaya baris riwayat lama tetap ditampilkan rapi,
 * bukan ikut memakai field Select/Input mentah (walau otomatis
 * di-disable oleh Filament di halaman View) milik form Create.
 */
class ViewPointTransaction extends ViewRecord
{
    protected static string $resource = PointTransactionResource::class;

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Transaksi')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('customer.name')
                        ->label('Customer')
                        ->content(fn (?PointTransaction $record) => $record?->customer?->name ?? '—'),

                    Forms\Components\Placeholder::make('type')
                        ->label('Tipe')
                        ->content(fn (?PointTransaction $record) => $record?->type === 'earn' ? 'Dapat Poin' : 'Pakai Poin'),

                    Forms\Components\Placeholder::make('points')
                        ->label('Jumlah Poin')
                        ->content(fn (?PointTransaction $record) => $record?->points),

                    Forms\Components\Placeholder::make('reference_type')
                        ->label('Sumber')
                        ->content(fn (?PointTransaction $record) => match ($record?->reference_type) {
                            'booking'                    => 'Booking (transaksi toko)',
                            'customer_referral'          => 'Bonus Ajak Teman',
                            'warranty'                   => 'Registrasi Garansi',
                            'reward_redemption'          => 'Tukar Reward',
                            'reward_redemption_refund'   => 'Refund Pembatalan Reward',
                            'reward_redemption_reversal' => 'Pembatalan Reward Dibatalkan',
                            'manual'                     => 'Entri Manual Admin',
                            default                      => $record?->reference_type ?? '—',
                        }),

                    Forms\Components\Placeholder::make('description')
                        ->label('Deskripsi')
                        ->columnSpanFull()
                        ->content(fn (?PointTransaction $record) => $record?->description ?? '—'),

                    Forms\Components\Placeholder::make('created_at')
                        ->label('Tanggal')
                        ->content(fn (?PointTransaction $record) => $record?->created_at?->format('d M Y H:i')),
                ]),
        ]);
    }
}
