<?php

namespace App\Filament\Resources\Support;

use Filament\Tables\Actions\Action;
use Illuminate\Database\Eloquent\Model;

/**
 * Tombol "Duplikat" di daftar sebuah Resource: membuka halaman Buat dengan form terisi dari data yang dipilih.
 * Resource yang memakai trait ini wajib mengisi duplicateData() dengan DAFTAR PUTIH kolom yang aman disalin,
 * dan halaman Buat-nya memakai App\Filament\Concerns\PrefillsFromDuplicate.
 */
trait DuplicatesRecords
{
    /**
     * Nilai form untuk data baru yang disalin dari $record (kunci = nama field form).
     *
     * @return array<string, mixed>
     */
    abstract public static function duplicateData(Model $record): array;

    public static function duplicateTableAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplikat')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->visible(fn () => static::canCreate())
            ->url(fn (Model $record): string => static::getUrl('create', ['duplikat' => $record->getKey()]));
    }
}
