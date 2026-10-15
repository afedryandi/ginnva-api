<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppSettingResource\Pages;
use App\Models\AppSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Teks Aplikasi" (Fase 4 pengerjaan ulang booking, 2026-10-20): teks yang tampil ke pelanggan dan bisa diubah admin tanpa
 * rilis aplikasi; saat ini kebijakan pembatalan booking. Kunci tetap (lihat AppSetting::DEFINITIONS): tidak ada tambah/hapus.
 */
class AppSettingResource extends Resource
{
    protected static ?string $model = AppSetting::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $cluster = \App\Filament\Clusters\BookingCluster::class;

    // Grup sidebar "Operasional" (band 100-199), setelah "Daftar Periksa QC".
    protected static ?string $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Teks Aplikasi';

    protected static ?string $modelLabel = 'Teks Aplikasi';

    protected static ?string $pluralModelLabel = 'Teks Aplikasi';

    protected static ?int $navigationSort = 104;

    private static function accessGate(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public static function canViewAny(): bool
    {
        return static::accessGate();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    // Teks ini tampil ke semua pelanggan, jadi hanya akses penuh (super admin/direksi) yang boleh mengubah.
    public static function canEdit($record): bool
    {
        return static::accessGate() && (auth()->user()?->isFullAccess() ?? false);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->whereIn('key', array_keys(AppSetting::DEFINITIONS));
    }

    public static function form(Form $form): Form
    {
        return $form->columns(1)->schema([
            Forms\Components\Placeholder::make('label')
                ->label('Pengaturan')
                ->content(fn (?AppSetting $record) => AppSetting::DEFINITIONS[$record?->key]['label'] ?? $record?->key),

            Forms\Components\Textarea::make('value')
                ->label('Isi Teks')
                ->rows(5)
                ->required()
                ->maxLength(1000)
                ->helperText(fn (?AppSetting $record) => AppSetting::DEFINITIONS[$record?->key]['help'] ?? null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('key')
                    ->label('Pengaturan')
                    ->formatStateUsing(fn (string $state) => AppSetting::DEFINITIONS[$state]['label'] ?? $state)
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('value')->label('Isi')->limit(120)->wrap(),
                Tables\Columns\TextColumn::make('updated_at')->label('Diubah')->since(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAppSettings::route('/'),
            'edit' => Pages\EditAppSetting::route('/{record}/edit'),
        ];
    }
}
