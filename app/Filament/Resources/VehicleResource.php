<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleResource\Pages;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    // Dipindah ke MasterDataCluster 2026-09-14 (struktur dropdown top-nav
    // "Lainnya" bertingkat, sama pola "Penjualan" — lihat catatan di
    // MasterDataCluster.php). Sub-heading sidebar $navigationGroup='Master
    // Data' yang lama TIDAK perlu lagi (cluster ini sudah homogen).
    protected static ?string $cluster = \App\Filament\Clusters\MasterDataCluster::class;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Kendaraan';

    protected static ?string $modelLabel = 'Kendaraan';

    protected static ?string $pluralModelLabel = 'Kendaraan';

    /**
     * Data master nasional, tidak ber-scope toko: staf dengan akses menu boleh MELIHAT semua baris (dipakai untuk pilihan kendaraan di
     * formulir). Mengubahnya (tambah, ubah, nonaktifkan, hapus) mengubah harga untuk SEMUA toko, jadi hanya akses penuh atau staf yang
     * diberi izin aksinya secara eksplisit di Hak Akses Detail (Master Data Fase 4, keputusan pemilik: pemilik data = akses penuh).
     */
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    /**
     * SEBELUMNYA tidak ada override di sini sama sekali, tanpa Policy
     * terdaftar — canCreate()/canEdit()/canDelete() bawaan Resource
     * selalu FALSE tanpa Policy, jadi EditAction/DeleteAction (baris)
     * tidak pernah muncul untuk siapa pun. LEBIH PARAH: BulkAction
     * "Hapus" custom di bawah (bukan DeleteBulkAction bawaan) TIDAK
     * ikut ter-auto-wire ke Gate sama sekali, jadi staff mana pun
     * dengan akses menu ini SELALU bisa hapus massal kendaraan lewat
     * situ walau tombol Hapus satuan tidak pernah muncul — sama pola
     * bug yang sudah diperbaiki di FilmProductResource. Ditemukan lewat
     * sapu bersih 2026-09-14 (audit framework, "Otorisasi default-deny").
     */
    public static function canCreate(): bool
    {
        return static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'create', false) ?? false);
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'update', false) ?? false);
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', false) ?? false);
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', false) ?? false);
    }

    /** Panduan memilih kelas ukuran: contoh model nyata per kelas dari data yang sudah ada. */
    public static function sizeHelp(): string
    {
        $parts = [];
        foreach (Vehicle::sizeExamples() as $size => $examples) {
            if ($examples !== []) {
                $parts[] = $size . ': ' . implode(', ', $examples);
            }
        }

        return 'Menentukan harga PPF dan kaca film (harga dibaca dari matriks per ukuran). Mengubah ukuran di sini mengubah harga acuan untuk semua booking dan penawaran berikutnya di SEMUA toko.'
            . ($parts ? ' Contoh yang sudah terdaftar: ' . implode(' | ', $parts) . '.' : '');
    }

    /**
     * Hapus kendaraan hanya bila belum dipakai di mana pun. Mengembalikan alasan (siap tampil) bila tidak bisa dihapus, null bila berhasil.
     */
    public static function tryDelete(Vehicle $record): ?string
    {
        $usage = $record->usageSummary();

        if ($usage !== []) {
            $text = collect($usage)->map(fn (int $count, string $label) => "{$count} {$label}")->implode(', ');

            return "Kendaraan ini dipakai oleh {$text}. Nonaktifkan kendaraan ini alih-alih menghapusnya, supaya riwayatnya tetap utuh.";
        }

        try {
            $record->delete();
        } catch (QueryException $e) {
            return 'Data kendaraan ini masih dipakai oleh penawaran atau studi kasus. Nonaktifkan kendaraan ini alih-alih menghapusnya.';
        }

        return null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Kendaraan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('brand')
                        ->label('Merek')
                        ->placeholder('Contoh: Honda, Toyota')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('model')
                        ->label('Tipe / Model')
                        ->placeholder('Contoh: Civic, Alphard (opsional — isi jika sudah diketahui)')
                        ->nullable()
                        ->maxLength(255),

                    // unique() di sini scoped ke brand+model lewat
                    // modifyRuleUsing — beda dari constraint database
                    // (unique(['brand','model','variant']) di migration).
                    // PENTING: rule ini cuma jalan kalau variant TERISI —
                    // Laravel Validator melewati SEMUA rule non-required
                    // (termasuk unique) untuk field ->nullable() yang
                    // nilainya kosong, jadi kasus varian kosong TIDAK
                    // tertangkap di sini sama sekali (dikonfirmasi lewat
                    // testing manual live, 2 kendaraan Merek+Tipe sama
                    // dengan varian sama-sama kosong lolos dobel).
                    // Ditutup di CreateVehicle/EditVehicle::
                    // mutateFormDataBeforeCreate/Save() sebagai jaring
                    // pengaman terakhir yang tidak kena skip-validator
                    // ini. Ditemukan & diperbaiki 2026-09-01.
                    Forms\Components\TextInput::make('variant')
                        ->label('Varian')
                        ->placeholder('Contoh: 1.5 RS CVT, 2.5 G AT')
                        ->nullable()
                        ->maxLength(255)
                        ->unique(
                            table: 'vehicles',
                            column: 'variant',
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule, Forms\Get $get) => $rule
                                ->where('brand', $get('brand'))
                                ->where('model', $get('model')),
                        )
                        ->validationMessages([
                            'unique' => 'Kendaraan dengan merek, model, dan varian yang sama sudah terdaftar.',
                        ]),

                    Forms\Components\Select::make('size_category')
                        ->label('Kategori Ukuran')
                        ->helperText(fn () => static::sizeHelp())
                        ->options([
                            'S'   => 'S — Kecil (city car, hatchback)',
                            'M'   => 'M — Sedang (sedan, MPV kecil)',
                            'L'   => 'L — Besar (SUV, MPV besar)',
                            'XL'  => 'XL — Sangat Besar (double cabin, van)',
                            'XXL' => 'XXL — Ekstra Besar (sport car, luxury)',
                        ])
                        ->in(['S', 'M', 'L', 'XL', 'XXL'])
                        ->required(),
                ]),

            Forms\Components\Section::make('Detail & Status')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('body_type')
                        ->label('Tipe Bodi')
                        ->options(Vehicle::BODY_TYPES)
                        ->native(false)
                        ->placeholder('Belum diisi')
                        ->helperText('Membantu memeriksa kategori ukuran dan membedakan generasi.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif di katalog')
                        ->default(true)
                        ->helperText('Nonaktifkan kendaraan yang tidak ditawarkan lagi. Riwayat penawaran dan kendaraan pelanggan tetap utuh; kendaraan nonaktif tidak muncul di aplikasi dan formulir.'),

                    Forms\Components\TextInput::make('year_from')
                        ->label('Tahun produksi dari')
                        ->numeric()->integer()->minValue(1980)->maxValue(2100)
                        ->placeholder('mis. 2019'),

                    Forms\Components\TextInput::make('year_to')
                        ->label('Tahun produksi sampai')
                        ->numeric()->integer()->minValue(1980)->maxValue(2100)
                        ->gte('year_from')
                        ->placeholder('kosong = masih diproduksi')
                        ->validationMessages(['gte' => 'Tahun akhir tidak boleh lebih kecil dari tahun awal.']),

                    Forms\Components\TextInput::make('notes')
                        ->label('Catatan')
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->placeholder('mis. generasi, kekhususan kaca/panel'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('brand')
                    ->label('Merek')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('model')
                    ->label('Tipe / Model')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('variant')
                    ->label('Varian')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('size_category')
                    ->label('Ukuran')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('body_type')
                    ->label('Tipe Bodi')
                    ->formatStateUsing(fn (?string $state) => $state ? (Vehicle::BODY_TYPES[$state] ?? $state) : null)
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif')
                    ->default(true),
                Tables\Filters\SelectFilter::make('size_category')
                    ->label('Kategori Ukuran')
                    ->options([
                        'S'   => 'S',
                        'M'   => 'M',
                        'L'   => 'L',
                        'XL'  => 'XL',
                        'XXL' => 'XXL',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('toggle_active')
                    ->label(fn (Vehicle $record) => $record->is_active ? 'Nonaktifkan' : 'Aktifkan')
                    ->icon(fn (Vehicle $record) => $record->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color('gray')
                    ->visible(fn () => static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'update', false) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Vehicle $record) => $record->is_active
                        ? 'Kendaraan tidak akan muncul lagi di aplikasi dan formulir. Riwayat penawaran dan kendaraan pelanggan tetap utuh.'
                        : 'Kendaraan akan muncul lagi di aplikasi dan formulir.')
                    ->action(function (Vehicle $record) {
                        $record->update(['is_active' => ! $record->is_active]);

                        Notification::make()->title($record->is_active ? 'Kendaraan diaktifkan' : 'Kendaraan dinonaktifkan')->success()->send();
                    }),
                // Hapus hanya untuk kendaraan yang belum dipakai di mana pun (salah ketik). Yang sudah dipakai: nonaktifkan.
                Tables\Actions\DeleteAction::make()
                    ->action(function (Vehicle $record) {
                        $blocked = static::tryDelete($record);

                        if ($blocked !== null) {
                            Notification::make()
                                ->title('Tidak bisa menghapus kendaraan ini')
                                ->body($blocked)
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Kendaraan dihapus')->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Hapus massal diproses satu-satu: baris yang belum dipakai terhapus, yang sudah dipakai dilewati dengan laporan jumlahnya
                    // (bukan DeleteBulkAction bawaan yang gagal seluruhnya). Audit modul Kendaraan 2026-08-29; aturan pemakaian 2026-10-21.
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Nonaktifkan')
                        ->icon('heroicon-o-eye-slash')
                        ->color('gray')
                        ->visible(fn () => static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'update', false) ?? false))
                        ->requiresConfirmation()
                        ->action(function (\Illuminate\Support\Collection $records) {
                            $records->each(fn (Vehicle $v) => $v->update(['is_active' => false]));

                            Notification::make()->title($records->count() . ' kendaraan dinonaktifkan')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),

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
                                if (static::tryDelete($record) === null) {
                                    $deleted++;
                                } else {
                                    $blocked++;
                                }
                            }

                            if ($blocked > 0) {
                                Notification::make()
                                    ->title($deleted > 0
                                        ? "{$deleted} kendaraan dihapus, {$blocked} tidak bisa dihapus"
                                        : 'Tidak ada kendaraan yang bisa dihapus')
                                    ->body("{$blocked} kendaraan masih dipakai oleh penawaran, studi kasus, atau kendaraan pelanggan, dilewati. Nonaktifkan saja kendaraan yang tidak ditawarkan lagi.")
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()->title("{$deleted} kendaraan dihapus")->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('brand')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\RelationManagers\ActivityHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicles::route('/'),
            'create' => Pages\CreateVehicle::route('/create'),
            'edit' => Pages\EditVehicle::route('/{record}/edit'),
        ];
    }
}