<?php

namespace App\Filament\Resources\RelationManagers;

use App\Filament\Resources\ActivityResource;
use App\Support\ActivityPresenter;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Tab "Riwayat" generik (Histori Aktivitas Fase 1, 2026-10-21): perubahan khusus rekaman yang sedang dibuka, dari activity_log,
 * tanpa harus mencari di daftar global. Dipasang di resource utama lewat getRelations(); model-nya harus memakai LogsActivity
 * (relasi `activities`). Isinya sama sensitifnya dengan menu Histori Aktivitas (siapa mengubah apa, nilai lama dan baru), jadi
 * tab hanya muncul bagi yang boleh membuka menu itu.
 */
class ActivityHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Riwayat';

    protected static ?string $icon = 'heroicon-o-clock';

    public static function canViewForRecord(Model $ownerRecord, string $pageName): bool
    {
        return ActivityResource::canViewAny();
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /** Aksi lihat digerbangi canView() bawaan yang selalu false tanpa policy; akses tab sudah dijaga canViewForRecord(). */
    protected function canView($record): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu (WIB)')
                    ->dateTime('d M Y, H:i', ActivityPresenter::TIMEZONE)
                    ->sortable(),

                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Pelaku')
                    ->placeholder('Sistem')
                    ->description(fn (Activity $record) => ActivityPresenter::actorNote($record)),

                Tables\Columns\TextColumn::make('event')
                    ->label('Aksi')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'created' => 'Dibuat',
                        'updated' => 'Diubah',
                        'deleted' => 'Dihapus',
                        'restored' => 'Dipulihkan',
                        null => 'Dicatat',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('description')
                    ->label('Keterangan')
                    ->state(fn (Activity $record) => ActivityPresenter::describe($record))
                    ->wrap(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->emptyStateHeading('Belum ada riwayat')
            ->emptyStateDescription('Perubahan pada rekaman ini otomatis tercatat di sini.');
    }

    public function infolist(Infolist $infolist): Infolist
    {
        // Detail sama dengan halaman Histori Aktivitas supaya label dan nilai konsisten.
        return ActivityResource::infolist($infolist);
    }
}
