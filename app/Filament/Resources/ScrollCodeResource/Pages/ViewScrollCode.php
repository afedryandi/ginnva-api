<?php

namespace App\Filament\Resources\ScrollCodeResource\Pages;

use App\Filament\Resources\ScrollCodeResource;
use App\Models\ScrollCode;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Halaman baru — sebelumnya ScrollCodeResource cuma punya halaman List,
 * jadi Riwayat Pemakaian (UsagesRelationManager) tidak ada tempat untuk
 * ditampilkan. Bukan EditRecord karena form()-nya sengaja semua field
 * disabled (data di sini cuma bisa diubah lewat action-action khusus,
 * bukan form edit biasa).
 *
 * Aksi Edit Panjang & Harga dan Mutasi ke Toko Lain juga tersedia di header halaman ini (audit Kode Gulungan, 2026-11-02), jadi admin
 * tidak perlu kembali ke daftar untuk mengoreksi gulungan yang sedang dilihat. Syarat tampilnya sama dengan aksi baris di daftar.
 */
class ViewScrollCode extends ViewRecord
{
    protected static string $resource = ScrollCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('edit_length')
                ->label('Edit Panjang & Harga')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                ->fillForm(fn (ScrollCode $record) => ScrollCodeResource::editLengthDefaults($record))
                ->form(ScrollCodeResource::editLengthSchema())
                ->action(function (ScrollCode $record, array $data, $livewire) {
                    ScrollCodeResource::applyLengthEdit($record, $data);
                    $livewire->redirect(ScrollCodeResource::getUrl('view', ['record' => $record]));
                }),

            Actions\Action::make('transfer')
                ->label('Mutasi ke Toko Lain')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('warning')
                ->visible(fn (ScrollCode $record) => (auth()->user()?->isFullAccess() ?? false) && $record->status === 'allocated')
                ->form(ScrollCodeResource::transferSchema())
                ->action(function (ScrollCode $record, array $data, $livewire) {
                    ScrollCodeResource::applyTransfer($record, $data);
                    $livewire->redirect(ScrollCodeResource::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
