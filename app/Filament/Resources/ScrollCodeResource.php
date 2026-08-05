<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScrollCodeResource\Pages;
use App\Models\FilmProduct;
use App\Models\ScrollCode;
use App\Models\Store;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ScrollCodeExport;

class ScrollCodeResource extends Resource
{
    protected static ?string $model = ScrollCode::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationGroup = 'Master Data';
    protected static ?string $navigationLabel = 'Kode Gulungan';

    protected static ?string $modelLabel = 'Kode Gulungan';

    protected static ?string $pluralModelLabel = 'Kode Gulungan';

    protected static ?int $navigationSort = 50;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')->label('Kode')->disabled(),
                    Forms\Components\TextInput::make('filmProduct.name')->label('Produk')->disabled(),
                    Forms\Components\TextInput::make('store.name')->label('Toko')->disabled(),
                    Forms\Components\TextInput::make('status')->label('Status')->disabled(),
                    Forms\Components\TextInput::make('warranty_code')->label('No. Garansi')->disabled(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('filmProduct.name')
                    ->label('Produk Film')
                    ->placeholder('—')
                    ->searchable()
                    ->description(function (ScrollCode $record): ?string {
                        $fp = $record->filmProduct;
                        if (! $fp) return null;

                        $type = $fp->product_type === 'ppf' ? 'PPF' : 'Kaca Film';

                        if ($fp->product_type !== 'window_film') {
                            return $type;
                        }

                        return $type . ' — ' . ($fp->position === 'front' ? 'Kaca Depan' : 'Samping & Belakang');
                    }),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'gray'    => 'unallocated',
                        'warning' => 'allocated',
                        'success' => 'used',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'unallocated' => 'Belum Dialokasi',
                        'allocated'   => 'Dialokasi',
                        'used'        => 'Terpakai',
                        default       => $state,
                    }),

                Tables\Columns\TextColumn::make('warranty_code')
                    ->label('No. Garansi')
                    ->placeholder('—')
                    ->searchable(),

                // Cuma relevan untuk Window Film (1 gulungan dipakai
                // berkali-kali) — untuk PPF selalu 0/1 karena langsung
                // 'used' di pemakaian pertama, lihat WarrantyObserver.
                Tables\Columns\TextColumn::make('usage_count')
                    ->label('Terpakai')
                    ->formatStateUsing(fn (ScrollCode $record) => $record->max_usage
                        ? "{$record->usage_count} / {$record->max_usage}"
                        : (string) $record->usage_count)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('allocated_at')
                    ->label('Dialokasi Pada')
                    ->dateTime('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('used_at')
                    ->label('Dipakai Pada')
                    ->dateTime('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'unallocated' => 'Belum Dialokasi',
                        'allocated'   => 'Dialokasi',
                        'used'        => 'Terpakai',
                    ]),

                Tables\Filters\SelectFilter::make('film_product_id')
                    ->label('Produk Film')
                    ->options(fn () => FilmProduct::pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->headerActions([
                // Input kode dari China — hanya super_admin
                Tables\Actions\Action::make('add_code')
                    ->label('Tambah Kode')
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->visible(fn () => auth()->user()?->isFullAccess())
                    ->form([
                        Forms\Components\TextInput::make('code')
                            ->label('Kode Gulungan')
                            ->placeholder('Masukkan kode dari gulungan fisik')
                            ->required(),

                        // Label disertai tipe (PPF/Kaca Film) + posisi
                        // (Depan/Samping & Belakang) supaya staff tidak
                        // salah pilih produk saat daftarkan kode baru —
                        // sebelumnya cuma nama polos, tidak ada konteks.
                        Forms\Components\Select::make('film_product_id')
                            ->label('Produk Film')
                            ->options(fn () => FilmProduct::where('is_active', true)
                                ->get()
                                ->mapWithKeys(function (FilmProduct $fp) {
                                    $type = $fp->product_type === 'ppf' ? 'PPF' : 'Kaca Film';

                                    $detail = $fp->product_type === 'window_film'
                                        ? ($fp->position === 'front' ? 'Kaca Depan' : 'Samping & Belakang')
                                        : null;

                                    $label = $detail ? "{$fp->name} — {$type} ({$detail})" : "{$fp->name} — {$type}";

                                    return [$fp->id => $label];
                                })
                            )
                            ->searchable()
                            ->required(),

                        Forms\Components\TextInput::make('max_usage')
                            ->label('Kapasitas Gulungan (opsional)')
                            ->helperText('Khusus Window Film — 1 gulungan biasanya cukup untuk ±30 mobil. Kosongkan kalau tidak tahu pastinya; nanti tandai manual lewat "Tandai Habis" saat gulungan fisik habis.')
                            ->numeric()
                            ->minValue(1),
                    ])
                    ->action(function (array $data) {
                        // Cek unique manual terhadap nilai yang SUDAH di-trim
                        // — sebelumnya rule unique:scroll_codes,code jalan
                        // atas input mentah (belum di-trim) sementara yang
                        // disimpan sudah di-trim, jadi kode ber-spasi bisa
                        // lolos validasi lalu tetap bentrok dengan UNIQUE
                        // constraint di database saat insert (error 500
                        // mentah, bukan pesan validasi yang rapi).
                        $code = trim($data['code']);

                        if (ScrollCode::where('code', $code)->exists()) {
                            Notification::make()
                                ->title('Kode gulungan sudah terdaftar')
                                ->body("Kode \"{$code}\" sudah ada di sistem.")
                                ->danger()
                                ->send();

                            return;
                        }

                        // Cek exists() di atas masih bisa race (2 admin
                        // tambah kode yang sama nyaris bersamaan) — dibekap
                        // UNIQUE constraint di DB, tapi exception-nya
                        // sebelumnya tidak ketangkap di sini sama sekali,
                        // jadi race itu muncul sebagai error 500 mentah.
                        try {
                            ScrollCode::create([
                                'code'            => $code,
                                'film_product_id' => $data['film_product_id'],
                                'max_usage'       => $data['max_usage'] ?? null,
                                'status'          => 'unallocated',
                            ]);
                        } catch (QueryException $e) {
                            Notification::make()
                                ->title('Kode gulungan sudah terdaftar')
                                ->body("Kode \"{$code}\" baru saja ditambahkan oleh proses lain.")
                                ->danger()
                                ->send();
                        }
                    }),


                // Export Excel
                Tables\Actions\Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn () => auth()->user()?->isFullAccess())
                    ->action(fn () => Excel::download(
                        new ScrollCodeExport(),
                        'scroll-codes-' . now()->format('Ymd') . '.xlsx'
                    )),
            ])
            ->actions([
                // Untuk Window Film — gulungan dipakai berkali-kali dan
                // TIDAK otomatis 'used' kecuali max_usage terisi & tercapai
                // (lihat WarrantyObserver). Staff/admin yang tahu gulungan
                // fisiknya sudah habis tandai manual lewat sini.
                Tables\Actions\Action::make('mark_used')
                    ->label('Tandai Habis')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ScrollCode $record) => $record->status === 'allocated')
                    ->requiresConfirmation()
                    ->modalDescription('Tandai kode gulungan ini sebagai habis? Kode ini tidak akan muncul lagi di pilihan saat input garansi baru.')
                    ->action(function (ScrollCode $record) {
                        // Lock + re-cek status di dalam lock — SEBELUMNYA
                        // langsung update() tanpa lock, bisa balapan dengan
                        // WarrantyObserver yang otomatis menandai status
                        // gulungan yang sama saat garansi baru diproses
                        // hampir bersamaan.
                        DB::transaction(function () use ($record) {
                            $locked = ScrollCode::where('id', $record->id)->lockForUpdate()->first();
                            if (! $locked || $locked->status !== 'allocated') return;

                            $locked->update(['status' => 'used', 'used_at' => now()]);
                        });
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (ScrollCode $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'unallocated'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('allocate')
                        ->label('Alokasi ke Toko')
                        ->icon('heroicon-o-building-storefront')
                        ->color('warning')
                        ->visible(fn () => auth()->user()?->isFullAccess())
                        ->form([
                            Forms\Components\Select::make('store_id')
                                ->label('Toko Tujuan')
                                ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data) {
                            // Lock tiap row + WAJIB masih 'unallocated' —
                            // SEBELUMNYA overwrite status/store_id tanpa
                            // cek status saat ini sama sekali, jadi bulk
                            // alokasi yang tidak sengaja mencakup kode yang
                            // sudah 'allocated'/'used' (mis. baru saja
                            // dipakai WarrantyObserver di request lain)
                            // bisa menimpanya balik ke 'allocated' —
                            // 1 kode fisik jadi kelihatan "belum dipakai"
                            // padahal sudah terpasang di mobil lain.
                            foreach ($records as $record) {
                                DB::transaction(function () use ($record, $data) {
                                    $locked = ScrollCode::where('id', $record->id)->lockForUpdate()->first();
                                    if (! $locked || $locked->status !== 'unallocated') return;

                                    $locked->update([
                                        'store_id'     => $data['store_id'],
                                        'status'       => 'allocated',
                                        'allocated_at' => now(),
                                    ]);
                                });
                            }
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isFullAccess()),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScrollCodes::route('/'),
        ];
    }
}
