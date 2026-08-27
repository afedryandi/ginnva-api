<?php

namespace App\Filament\Resources\RawMaterialResource\RelationManagers;

use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

/**
 * Sebelumnya perubahan (nama, harga, ambang stok menipis, dst — lihat
 * RawMaterial::getActivitylogOptions()) SUDAH tercatat di tabel
 * activity_log, tapi tidak ada tempat melihatnya dari menu Bahan Baku —
 * cuma bisa lewat "Histori Aktivitas" global (App\Filament\Resources\ActivityResource)
 * yang mencampur semua modul jadi 1 daftar panjang, dan bahkan tidak
 * melabeli log_name 'raw_material' di filter/badge-nya. Tab ini
 * menampilkan riwayat perubahan KHUSUS 1 bahan baku ini saja.
 */
class ActivityLogRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Riwayat Perubahan';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Diubah Oleh')
                    ->placeholder('Sistem'),

                Tables\Columns\BadgeColumn::make('event')
                    ->label('Aksi')
                    ->colors([
                        'success' => 'created',
                        'warning' => 'updated',
                        'danger'  => 'deleted',
                    ])
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'created' => 'Dibuat',
                        'updated' => 'Diubah',
                        'deleted' => 'Dihapus',
                        default   => $state ?? '—',
                    }),

                Tables\Columns\TextColumn::make('description')
                    ->label('Deskripsi')
                    ->limit(60),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Belum ada riwayat perubahan')
            ->emptyStateDescription('Perubahan pada bahan baku ini (nama, harga, ambang stok menipis, dst) otomatis tercatat di sini.');
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
            TextEntry::make('causer.name')->label('Diubah Oleh')->placeholder('Sistem (otomatis)'),
            TextEntry::make('description')->label('Deskripsi')->columnSpanFull(),
            KeyValueEntry::make('properties.old')
                ->label('Nilai Sebelumnya')
                ->columnSpanFull()
                ->visible(fn (Activity $record) => filled($record->properties['old'] ?? null)),
            KeyValueEntry::make('properties.attributes')
                ->label('Nilai Baru')
                ->columnSpanFull()
                ->visible(fn (Activity $record) => filled($record->properties['attributes'] ?? null)),
        ]);
    }
}
