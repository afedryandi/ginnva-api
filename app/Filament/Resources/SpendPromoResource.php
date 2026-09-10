<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SpendPromoResource\Pages;
use App\Filament\Resources\SpendPromoResource\RelationManagers\BookingsRelationManager;
use App\Models\SpendPromo;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Promo Per Total Pembelian" — aturan potongan flat Rp untuk booking
 * yang total (kotor) transaksinya >= ambang. Diterapkan MANUAL oleh staf
 * di form Booking (field "Promo Total Pembelian"). Diminta 2026-09-10,
 * analog menu Majoo "Promo > Per Total Pembelian".
 */
class SpendPromoResource extends Resource
{
    protected static ?string $model = SpendPromo::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $cluster = \App\Filament\Clusters\PromosiCluster::class;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Promo Total Pembelian';

    protected static ?string $modelLabel = 'Promo Total Pembelian';

    protected static ?string $pluralModelLabel = 'Promo Total Pembelian';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Aturan Promo')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Promo')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Mis. Cashback PPF Full Body'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true),

                    Forms\Components\TextInput::make('min_purchase_amount')
                        ->label('Minimal Pembelian (kotor)')
                        ->helperText('Nilai transaksi SEBELUM potongan harus mencapai angka ini.')
                        ->numeric()
                        ->prefix('Rp')
                        ->minValue(0)
                        ->required(),

                    Forms\Components\TextInput::make('discount_amount')
                        ->label('Potongan Harga')
                        ->helperText('Potongan flat yang diberikan ke booking yang memenuhi syarat.')
                        ->numeric()
                        ->prefix('Rp')
                        ->minValue(0)
                        ->required(),

                    Forms\Components\DatePicker::make('starts_on')
                        ->label('Mulai Berlaku')
                        ->native(false)
                        ->helperText('Kosongkan = tanpa batas awal.'),

                    Forms\Components\DatePicker::make('ends_on')
                        ->label('Berakhir')
                        ->native(false)
                        ->helperText('Kosongkan = tanpa batas akhir.'),

                    Forms\Components\Textarea::make('description')
                        ->label('Deskripsi')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('min_purchase_amount')
                    ->label('Min. Pembelian')
                    ->money('IDR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('discount_amount')
                    ->label('Potongan')
                    ->money('IDR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('period')
                    ->label('Periode')
                    ->state(fn (SpendPromo $r) => trim(
                        ($r->starts_on?->translatedFormat('d M Y') ?? '—') . '  s/d  ' . ($r->ends_on?->translatedFormat('d M Y') ?? '—')
                    )),

                Tables\Columns\TextColumn::make('bookings_count')
                    ->label('Dipakai')
                    ->counts('bookings')
                    ->badge()
                    ->suffix(' booking'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (SpendPromo $r) => ! $r->is_active ? 'Nonaktif' : ($r->isRunning() ? 'Berjalan' : 'Terjadwal / Lewat'))
                    ->color(fn (SpendPromo $r) => ! $r->is_active ? 'gray' : ($r->isRunning() ? 'success' : 'warning')),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            BookingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSpendPromos::route('/'),
            'create' => Pages\CreateSpendPromo::route('/create'),
            'edit' => Pages\EditSpendPromo::route('/{record}/edit'),
        ];
    }
}
