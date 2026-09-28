<?php

namespace App\Filament\Resources\WorkScheduleResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Karyawan yang memakai / pernah memakai template ini (gap audit Daftar
 * Jadwal Kerja 2026-09-28: sebelumnya tidak ada cara melihat siapa yang
 * terdampak dari layar template). Read-only -- penugasan dibuat lewat
 * aksi "Terapkan ke Karyawan", bukan diedit manual di sini.
 */
class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'Penugasan Karyawan';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'assignedBy']))
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Karyawan')
                    ->searchable(),

                Tables\Columns\TextColumn::make('effective_from')
                    ->label('Berlaku Mulai')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('effective_to')
                    ->label('Berakhir')
                    ->date('d M Y')
                    ->placeholder('Masih berlaku'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn ($record) => $record->effective_from->isFuture()
                        ? 'Akan mulai'
                        : (($record->effective_to === null || $record->effective_to->gte(today())) ? 'Aktif' : 'Berakhir'))
                    ->color(fn (string $state) => match ($state) {
                        'Aktif' => 'success',
                        'Akan mulai' => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('assignedBy.name')
                    ->label('Ditugaskan Oleh')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('effective_from', 'desc');
    }
}
