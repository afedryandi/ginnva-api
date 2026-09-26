<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerGroupResource\Pages;
use App\Models\CustomerGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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
                // Gap diperbaiki 2026-09-26 (audit Grup Pelanggan) --
                // SEBELUMNYA tanpa minValue(0), angka negatif bisa masuk.
                ->minValue(0)
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

                // Gap diperbaiki 2026-09-26 (audit Grup Pelanggan) --
                // SEBELUMNYA tidak ada indikator dampak harga sebelum
                // staff hapus/nonaktifkan grup, cuma jumlah pelanggan.
                Tables\Columns\TextColumn::make('group_prices_count')
                    ->label('Jumlah Harga Khusus')
                    ->counts('groupPrices')
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
                    ->modalDescription(fn (CustomerGroup $record) => $record->groupPrices()->exists()
                        ? "Grup yang masih dipakai pelanggan tidak bisa dihapus — nonaktifkan saja kalau tidak mau dipakai lagi. {$record->groupPrices()->count()} harga khusus produk untuk grup ini akan ikut terhapus permanen."
                        : 'Grup yang masih dipakai pelanggan tidak bisa dihapus — nonaktifkan saja kalau tidak mau dipakai lagi.'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Bug diperbaiki 2026-09-26 (audit Grup Pelanggan) --
                    // SEBELUMNYA DeleteBulkAction::make() bawaan, yang
                    // cuma digerbangi canDeleteAny() dan TIDAK PERNAH
                    // memanggil canDelete() per baris terpilih. Staff bisa
                    // bulk-delete grup yang MASIH DIPAKAI banyak customer
                    // -- kontradiksi langsung dengan modal DeleteAction
                    // satuan di atas yang menjanjikan "tidak bisa
                    // dihapus". customer_group_id di-nullOnDelete() (BUKAN
                    // restrictOnDelete), jadi tidak ada QueryException
                    // yang bisa ditangkap seperti pola FilmProductResource
                    // -- pengecekan customers()->exists() harus eksplisit
                    // di sini, sama persis logic canDelete().
                    Tables\Actions\BulkAction::make('delete')
                        ->label('Hapus')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn () => static::canDeleteAny())
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Support\Collection $records) {
                            $deleted = 0;
                            $blocked = 0;

                            foreach ($records as $record) {
                                if ($record->customers()->exists()) {
                                    $blocked++;
                                    continue;
                                }

                                $record->delete();
                                $deleted++;
                            }

                            if ($blocked > 0) {
                                Notification::make()
                                    ->title($deleted > 0
                                        ? "{$deleted} grup dihapus, {$blocked} tidak bisa dihapus"
                                        : 'Tidak ada grup yang bisa dihapus')
                                    ->body("{$blocked} grup masih dipakai pelanggan, dilewati. Nonaktifkan lewat toggle \"Aktif\" saja kalau perlu.")
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()->title("{$deleted} grup dihapus")->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
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
