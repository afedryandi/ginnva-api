<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeChecklistItemResource\Pages;
use App\Models\ChecklistTemplate;
use App\Models\EmployeeChecklistItem;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Checklist Karyawan (Karyawan Fase 3, 2026-10-31): butir onboarding/offboarding per karyawan yang dicentang HR satu per satu. Butir
 * dibuat lewat aksi "Mulai Checklist Karyawan" di daftar ini (disalin dari Template Checklist), bukan satu per satu.
 */
class EmployeeChecklistItemResource extends Resource
{
    protected static ?string $model = EmployeeChecklistItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Kebijakan & Checklist';

    protected static ?string $navigationLabel = 'Checklist Masuk & Keluar';

    protected static ?string $modelLabel = 'Butir Checklist';

    protected static ?string $pluralModelLabel = 'Checklist Onboarding & Offboarding';

    protected static ?int $navigationSort = 335;

    public static function canViewAny(): bool
    {
        if (! config('features.employee_checklists')) {
            return false;
        }

        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    /** Dipakai aksi "Mulai Checklist Karyawan"; butir tidak dibuat lewat form biasa. */
    public static function canCreate(): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'create', true) ?? false);
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'update', true) ?? false);
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')->label('Karyawan')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('kind')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'onboarding' ? 'Onboarding' : 'Offboarding'),
                Tables\Columns\TextColumn::make('title')->label('Butir')->wrap(),
                Tables\Columns\IconColumn::make('is_done')->label('Selesai')->boolean(),
                Tables\Columns\TextColumn::make('done_at')->label('Selesai pada')->dateTime('d M Y H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('doneBy.name')->label('Oleh')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('notes')->label('Catatan')->placeholder('—')->limit(40)->toggleable(),
            ])
            ->defaultSort('user_id')
            ->filters([
                Tables\Filters\SelectFilter::make('kind')->label('Jenis')->options(ChecklistTemplate::KINDS),
                Tables\Filters\SelectFilter::make('user_id')->label('Karyawan')->relationship('user', 'name')->searchable(),
                Tables\Filters\TernaryFilter::make('is_done')->label('Selesai'),
            ])
            ->actions([
                Tables\Actions\Action::make('toggle')
                    ->label(fn (EmployeeChecklistItem $record) => $record->is_done ? 'Batalkan' : 'Tandai selesai')
                    ->icon(fn (EmployeeChecklistItem $record) => $record->is_done ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-check-circle')
                    ->color(fn (EmployeeChecklistItem $record) => $record->is_done ? 'gray' : 'success')
                    ->visible(fn () => static::canEdit(null))
                    ->form([
                        Forms\Components\Textarea::make('notes')->label('Catatan (opsional)')->rows(2),
                    ])
                    ->fillForm(fn (EmployeeChecklistItem $record) => ['notes' => $record->notes])
                    ->action(fn (EmployeeChecklistItem $record, array $data) => $record->markDone(! $record->is_done, auth()->id(), $data['notes'] ?? null)),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['user:id,name', 'doneBy:id,name']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployeeChecklistItems::route('/'),
        ];
    }
}
