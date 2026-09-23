<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerGroupResource\Pages;
use App\Models\CustomerGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Grup Pelanggan" (audit Majoo f40), dibangun 2026-09-22 atas
 * keputusan user — menjawab pertanyaan terbuka soal harga per grup
 * pelanggan. Assign customer ke grup lewat bulk action "Atur Grup
 * Pelanggan" di CustomerResource (bukan checklist di halaman ini —
 * lebih scalable untuk jumlah customer besar, cari/filter dulu baru
 * bulk-assign, pola sama seperti bulk action Filament lain).
 */
class CustomerGroupResource extends Resource
{
    protected static ?string $model = CustomerGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $cluster = \App\Filament\Clusters\PelangganCluster::class;

    protected static ?string $navigationLabel = 'Grup Pelanggan';

    protected static ?string $modelLabel = 'Grup Pelanggan';

    protected static ?string $pluralModelLabel = 'Grup Pelanggan';

    protected static ?int $navigationSort = 2;

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
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'create', true) ?? false);
    }

    public static function canView($record): bool
    {
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'view', true) ?? false);
    }

    public static function canEdit($record): bool
    {
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'update', true) ?? false);
    }

    public static function canDelete($record): bool
    {
        return static::accessGate()
            && ! $record->customers()->exists()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', true) ?? false);
    }

    public static function canDeleteAny(): bool
    {
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', true) ?? false);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama Grup')
                ->placeholder('mis. Member, Korporat, Reseller')
                ->required()
                ->maxLength(100)
                ->unique(ignoreRecord: true),

            Forms\Components\Textarea::make('description')
                ->label('Deskripsi (opsional)')
                ->rows(2),

            Forms\Components\TextInput::make('sort_order')
                ->label('Urutan')
                ->numeric()
                ->default(0)
                ->helperText('Angka lebih kecil tampil lebih dulu di daftar pilihan grup.'),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Nonaktifkan supaya tidak muncul lagi sebagai pilihan baru, tanpa mengubah customer yang sudah memakainya.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Grup')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Deskripsi')
                    ->limit(50)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('customers_count')
                    ->label('Jumlah Pelanggan')
                    ->counts('customers')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->modalDescription('Grup yang masih dipakai pelanggan tidak bisa dihapus — nonaktifkan saja kalau tidak mau dipakai lagi.'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomerGroups::route('/'),
            'create' => Pages\CreateCustomerGroup::route('/create'),
            'edit' => Pages\EditCustomerGroup::route('/{record}/edit'),
        ];
    }
}
