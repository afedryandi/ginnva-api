<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SalaryGradeResource\Pages;
use App\Models\SalaryGrade;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Struktur & Skala Upah (Karyawan Fase 3, 2026-10-31): rentang gaji pokok per golongan/jabatan. Gaji pokok karyawan di luar rentang
 * golongannya ditandai di laporan Kelengkapan Data Karyawan (peringatan saja). Golongan yang masih dipakai karyawan tidak bisa dihapus.
 */
class SalaryGradeResource extends Resource
{
    protected static ?string $model = SalaryGrade::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Penggajian';

    protected static ?string $navigationLabel = 'Struktur & Skala Upah';

    protected static ?string $modelLabel = 'Golongan Upah';

    protected static ?string $pluralModelLabel = 'Struktur & Skala Upah';

    protected static ?int $navigationSort = 430;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

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
        return ! $record->users()->exists()
            && static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', true) ?? false);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Golongan/Jabatan')->required()->maxLength(120)->unique(ignoreRecord: true)
                        ->placeholder('mis. Installer Junior')->columnSpanFull(),
                    Forms\Components\TextInput::make('min_salary')->label('Gaji pokok minimum')->numeric()->prefix('Rp')->required()->minValue(0)
                        ->helperText('Sebaiknya tidak di bawah upah minimum setempat (dicek HR).'),
                    Forms\Components\TextInput::make('max_salary')->label('Gaji pokok maksimum')->numeric()->prefix('Rp')->required()->minValue(0)
                        ->gte('min_salary'),
                    Forms\Components\Textarea::make('notes')->label('Keterangan')->rows(2)->columnSpanFull(),
                    Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Golongan/Jabatan')->searchable(),
                Tables\Columns\TextColumn::make('min_salary')->label('Minimum')->money('IDR', locale: 'id'),
                Tables\Columns\TextColumn::make('max_salary')->label('Maksimum')->money('IDR', locale: 'id'),
                Tables\Columns\TextColumn::make('users_count')->label('Karyawan')->counts('users'),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('min_salary')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalaryGrades::route('/'),
            'create' => Pages\CreateSalaryGrade::route('/create'),
            'edit' => Pages\EditSalaryGrade::route('/{record}/edit'),
        ];
    }
}
