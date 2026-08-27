<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VoucherResource\Pages;
use App\Filament\Resources\VoucherResource\RelationManagers;
use App\Models\Voucher;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;

class VoucherResource extends Resource
{
    protected static ?string $model = Voucher::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Marketing/Konten';

    protected static ?int $navigationSort = 80;

    protected static ?string $navigationLabel = 'Voucher Promo';

    protected static ?string $modelLabel = 'Voucher';

    protected static ?string $pluralModelLabel = 'Voucher Promo';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    /**
     * Voucher adalah kampanye promo company-wide (tidak terikat satu
     * toko) — create/edit dibatasi super_admin/direksi saja supaya
     * store_manager tidak bisa mengubah kampanye milik toko lain.
     */
    public static function canCreate(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Kampanye Voucher')
                ->description('Kelangkaan voucher ditentukan dari stok (mis. "200 pembeli pertama") — tanggal kedaluwarsa opsional, kosongkan kalau tidak perlu.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Kampanye')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Contoh: Voucher Rp10.000.000 — 200 Pembeli Pertama')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->label('Deskripsi (ditampilkan ke customer)')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('discount_amount')
                        ->label('Nominal Potongan (Rp)')
                        ->numeric()
                        ->required()
                        ->minValue(1),

                    Forms\Components\TextInput::make('total_stock')
                        ->label('Total Stok (jumlah voucher fisik dicetak)')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->disabled(fn (?Voucher $record) => $record !== null)
                        ->dehydrated()
                        ->helperText(fn (?Voucher $record) => $record
                            ? "Sudah di-assign: {$record->claimed_count} — stok tidak bisa diubah setelah kampanye jalan supaya tidak mengacaukan pelacakan."
                            : null),

                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label('Kedaluwarsa (opsional)')
                        ->helperText('Kosongkan kalau voucher berlaku selama stok masih ada.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Kampanye')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('discount_amount')
                    ->label('Potongan')
                    ->money('IDR', locale: 'id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('claimed_count')
                    ->label('Ter-assign')
                    ->formatStateUsing(fn (Voucher $record) => "{$record->claimed_count} / {$record->total_stock}")
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Kedaluwarsa')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Tanpa batas waktu')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Tombol disembunyikan kalau sudah ada klaim (UX). Database
                // (voucher_claims.voucher_id sekarang restrictOnDelete())
                // jadi lapisan pengaman sungguhan di baliknya — try-catch
                // di sini menangani seandainya tetap tertembus lewat
                // request yang dimanipulasi.
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Voucher $record) => $record->claimed_count === 0)
                    ->action(function (Voucher $record) {
                        try {
                            $record->delete();
                        } catch (QueryException $e) {
                            Notification::make()
                                ->title('Tidak bisa menghapus voucher ini')
                                ->body('Kampanye voucher ini masih punya klaim yang tercatat.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Voucher dihapus')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ClaimsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListVouchers::route('/'),
            'create' => Pages\CreateVoucher::route('/create'),
            'edit'   => Pages\EditVoucher::route('/{record}/edit'),
        ];
    }
}
