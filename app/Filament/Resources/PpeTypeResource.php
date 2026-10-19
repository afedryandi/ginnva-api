<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\HasKaryawanAccess;
use App\Filament\Resources\PpeTypeResource\Pages;
use App\Models\PpeType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Jenis APD & Perlengkapan Kerja (Karyawan Fase 5, 2026-11-02): daftar jenis dan umur pakai bawaannya. Angka bawaan hanya titik awal;
 * sesuaikan dengan SDS produk dan kebijakan K3. Jenis yang sudah pernah dibagikan tidak bisa dihapus (nonaktifkan saja).
 */
class PpeTypeResource extends Resource
{
    use HasKaryawanAccess {
        canDelete as private traitCanDelete;
    }

    protected static ?string $model = PpeType::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'K3 & Kinerja';

    protected static ?string $navigationLabel = 'Jenis APD';

    protected static ?string $modelLabel = 'Jenis APD';

    protected static ?string $pluralModelLabel = 'Jenis APD & Perlengkapan';

    protected static ?int $navigationSort = 510;

    public static function canDelete($record): bool
    {
        return ! $record->issuances()->exists() && static::traitCanDelete($record);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(120)->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('replace_after_months')
                    ->label('Umur pakai (bulan)')
                    ->numeric()->integer()->minValue(1)->maxValue(120)
                    ->helperText('Dipakai menghitung jatuh tempo penggantian otomatis. Kosongkan bila tidak ada jadwal ganti.'),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('replace_after_months')->label('Umur pakai')->suffix(' bulan')->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPpeTypes::route('/'),
            'create' => Pages\CreatePpeType::route('/create'),
            'edit' => Pages\EditPpeType::route('/{record}/edit'),
        ];
    }
}
