<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScrollCodeResource\Pages;
use App\Filament\Resources\ScrollCodeResource\RelationManagers\UsagesRelationManager;
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
        $query = parent::getEloquentQuery()->with('inventoryItem:id,scroll_code_id,code');
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

                // Kode gulungan (tercetak di fisik gulungan) beda dari
                // kode barang (INV-xxx, di-generate sistem untuk QR
                // kardus/kemasan) — sebelumnya tidak kelihatan sama sekali
                // dari menu ini, staff harus buka menu Produk PPF/WF dulu
                // dan cari manual kalau mau tahu kode barangnya.
                Tables\Columns\TextColumn::make('inventoryItem.code')
                    ->label('Kode Barang')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (ScrollCode $record) => $record->inventoryItem ? 'primary' : 'gray')
                    ->copyable()
                    ->copyMessage('Kode disalin')
                    ->searchable()
                    ->toggleable()
                    // Klik langsung ke halaman edit Produk PPF/WF terkait
                    // — pasangan dari link di InventoryItemResource
                    // ("Kode Gulungan" -> sini).
                    ->url(fn (ScrollCode $record) => $record->inventoryItem
                        ? InventoryItemResource::getUrl('edit', ['record' => $record->inventoryItem->id])
                        : null),

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

                // Dihitung langsung dari tabel warranties (bukan cuma
                // kolom warranty_code di baris ini) — 1 kode gulungan bisa
                // dipakai >1 warranty sejak tidak lagi otomatis single-use,
                // jadi kolom warranty_code yang cuma nyimpan 1 nilai akan
                // ketimpa (nampilkan yang PALING TERAKHIR pakai doang) kalau
                // tidak dihitung ulang begini.
                Tables\Columns\TextColumn::make('warranty_code')
                    ->label('No. Garansi')
                    ->placeholder('—')
                    ->getStateUsing(fn (ScrollCode $record) => $record->warranties()->pluck('warranty_code')->implode(', ') ?: null)
                    ->wrap()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->orWhereExists(function ($sub) use ($search) {
                            $sub->selectRaw('1')
                                ->from('warranties')
                                ->where('warranties.warranty_code', 'like', "%{$search}%")
                                ->where(function ($w) {
                                    $w->whereColumn('warranties.roll_number', 'scroll_codes.code')
                                        ->orWhereColumn('warranties.roll_number_2', 'scroll_codes.code')
                                        ->orWhereColumn('warranties.roll_number_front', 'scroll_codes.code')
                                        ->orWhereColumn('warranties.roll_number_side_rear', 'scroll_codes.code');
                                });
                        });
                    }),

                // Berlaku untuk PPF maupun Window Film — hitung berapa kali
                // kode ini dipakai, auto-'used' hanya kalau max_usage diisi
                // dan tercapai. Lihat WarrantyObserver.
                Tables\Columns\TextColumn::make('usage_count')
                    ->label('Terpakai')
                    ->formatStateUsing(fn (ScrollCode $record) => $record->max_usage
                        ? "{$record->usage_count} / {$record->max_usage}"
                        : (string) $record->usage_count)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('remaining_length_meters')
                    ->label('Sisa Panjang')
                    ->placeholder('—')
                    ->formatStateUsing(fn (ScrollCode $record) => $record->total_length_meters !== null
                        ? number_format((float) $record->remaining_length_meters, 2) . ' / ' . number_format((float) $record->total_length_meters, 2) . ' m'
                        : null)
                    ->badge()
                    ->color(fn (ScrollCode $record) => $record->total_length_meters !== null && (float) $record->remaining_length_meters <= 0
                        ? 'danger'
                        : 'info')
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
                            ->required()
                            // Isi otomatis Total Panjang begitu produk dipilih
                            // — standar PPF 15m, Window Film 30m. Staff tetap
                            // bisa edit manual kalau gulungan ini beda (mis.
                            // sisa tabungan dari gulungan lain, lihat catatan
                            // di InventoryItemResource).
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                $product = $state ? FilmProduct::find($state) : null;
                                if ($product) {
                                    $set('total_length_meters', $product->product_type === 'ppf' ? 15 : 30);
                                }
                            }),

                        Forms\Components\TextInput::make('max_usage')
                            ->label('Kapasitas Gulungan (opsional)')
                            ->helperText('Boleh dikosongkan (berlaku untuk PPF maupun Window Film) — kode akan tetap muncul di pilihan warranty baru sampai ditandai habis manual lewat "Tandai Habis". Isi kalau kamu tahu pasti gulungan ini cuma cukup untuk sekian mobil (mis. Window Film ±30 mobil), supaya otomatis ditandai habis begitu tercapai.')
                            ->numeric()
                            ->minValue(1),

                        Forms\Components\TextInput::make('total_length_meters')
                            ->label('Total Panjang (meter)')
                            ->helperText('Terisi otomatis dari produk yang dipilih (PPF 15m, Window Film 30m) — ubah manual kalau gulungan ini beda, mis. sisa tabungan dari gulungan lain.')
                            ->numeric()
                            ->required()
                            ->minValue(0.01),
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
                                'total_length_meters'     => $data['total_length_meters'] ?? null,
                                'remaining_length_meters' => $data['total_length_meters'] ?? null,
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

                // Import massal DIPINDAH ke menu Barang (InventoryItemResource)
                // — supaya kode gulungan hasil import juga otomatis tercatat
                // sebagai unit fisik di Inventaris (dapat kode/QR sendiri),
                // bukan cuma masuk ke tabel scroll_codes tanpa jejak stok.

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
                Tables\Actions\ViewAction::make()
                    ->label('Riwayat')
                    ->icon('heroicon-o-clock'),

                // Catat berapa meter dipakai dari gulungan ini — cuma
                // muncul kalau total_length_meters pernah diisi (kalau
                // tidak, sistem tidak tahu berapa sisa awalnya). Otomatis
                // 'used' begitu sisa mencapai 0, lihat ScrollCode::recordUsage().
                Tables\Actions\Action::make('record_usage')
                    ->label('Catat Pemakaian')
                    ->icon('heroicon-o-scissors')
                    ->color('warning')
                    ->visible(fn (ScrollCode $record) => $record->total_length_meters !== null && $record->status !== 'used')
                    ->form([
                        Forms\Components\TextInput::make('meters')
                            ->label('Meter Dipakai')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->suffix('meter')
                            ->helperText(fn (ScrollCode $record) => 'Sisa saat ini: ' . number_format((float) $record->remaining_length_meters, 2) . ' meter.'),

                        Forms\Components\Textarea::make('note')
                            ->label('Catatan (opsional)')
                            ->placeholder('Mis. dipakai untuk mobil apa, no. polisi, nama customer'),
                    ])
                    ->action(function (ScrollCode $record, array $data) {
                        try {
                            $record->recordUsage((float) $data['meters'], auth()->id(), $data['note'] ?? null);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()
                                ->title('Tidak bisa mencatat pemakaian')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Pemakaian dicatat')->success()->send();
                    }),

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

                // Satu-satunya jalan mengisi/koreksi Total & Sisa Panjang
                // untuk kode yang statusnya SUDAH allocated/used sebelum
                // fitur meter ada — bulk action "Isi Total Panjang
                // Standar" sengaja melewati kode begini (sisa sebenarnya
                // tidak diketahui sistem), jadi harus manual di sini kalau
                // adminnya tahu/bisa perkirakan sisa fisiknya.
                Tables\Actions\Action::make('edit_length')
                    ->label('Edit Panjang')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->isFullAccess())
                    ->fillForm(fn (ScrollCode $record) => [
                        'total_length_meters' => $record->total_length_meters,
                        'remaining_length_meters' => $record->remaining_length_meters,
                    ])
                    ->form([
                        Forms\Components\TextInput::make('total_length_meters')
                            ->label('Total Panjang (meter)')
                            ->numeric()
                            ->minValue(0.01),

                        Forms\Components\TextInput::make('remaining_length_meters')
                            ->label('Sisa Panjang (meter)')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Perkirakan sisa fisik gulungan sekarang kalau kode ini sudah pernah dipakai sebelum fitur ini ada.'),
                    ])
                    ->action(function (ScrollCode $record, array $data) {
                        $record->update([
                            'total_length_meters' => $data['total_length_meters'] ?? null,
                            'remaining_length_meters' => $data['remaining_length_meters'] ?? null,
                        ]);

                        Notification::make()->title('Data panjang diperbarui')->success()->send();
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

                    // Backfill untuk kode lama (dibuat sebelum fitur
                    // pelacakan meter ada) — cuma isi yang statusnya
                    // 'unallocated' (belum pernah dipakai sama sekali),
                    // SENGAJA lewati yang 'allocated'/'used' karena kita
                    // tidak tahu berapa meter yang sudah kepakai duluan di
                    // gulungan itu — mengisi total/sisa penuh untuk kode
                    // yang sudah separuh dipakai akan salah menampilkan
                    // sisa yang lebih besar dari yang sebenarnya.
                    Tables\Actions\BulkAction::make('fill_default_length')
                        ->label('Isi Total Panjang Standar')
                        ->icon('heroicon-o-arrows-pointing-out')
                        ->color('gray')
                        ->visible(fn () => auth()->user()?->isFullAccess())
                        ->requiresConfirmation()
                        ->modalDescription('Isi Total Panjang otomatis (PPF 15m, Window Film 30m) untuk kode terpilih yang belum punya nilai ini DAN masih berstatus "Belum Dialokasi". Kode yang sudah dialokasi/dipakai dilewati karena sisa meter sebenarnya tidak diketahui.')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $filled = 0;
                            $skipped = 0;

                            foreach ($records->loadMissing('filmProduct') as $record) {
                                if ($record->total_length_meters !== null || $record->status !== 'unallocated') {
                                    $skipped++;
                                    continue;
                                }

                                $length = $record->filmProduct?->product_type === 'ppf' ? 15 : 30;
                                $record->update([
                                    'total_length_meters' => $length,
                                    'remaining_length_meters' => $length,
                                ]);
                                $filled++;
                            }

                            Notification::make()
                                ->title('Selesai')
                                ->body("{$filled} kode diisi. " . ($skipped > 0 ? "{$skipped} kode dilewati (sudah punya nilai, atau sudah dialokasi/dipakai)." : ''))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    // BUKAN Tables\Actions\DeleteBulkAction::make() biasa —
                    // itu tidak punya pengaman status sama sekali, beda
                    // dari DeleteAction per-baris di atas yang sengaja
                    // dibatasi cuma untuk 'unallocated'. Bulk polos bisa
                    // menghapus kode yang sudah allocated/used sekaligus,
                    // menghilangkan riwayat alokasi/pemakaiannya.
                    Tables\Actions\BulkAction::make('delete_unallocated')
                        ->label('Hapus')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn () => auth()->user()?->isFullAccess())
                        ->requiresConfirmation()
                        ->modalDescription('Hapus kode gulungan terpilih? Cuma yang masih "Belum Dialokasi" yang akan dihapus — kode yang sudah dialokasi/dipakai dilewati.')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $deleted = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if ($record->status === 'unallocated') {
                                    $record->delete();
                                    $deleted++;
                                } else {
                                    $skipped++;
                                }
                            }

                            Notification::make()
                                ->title('Selesai')
                                ->body("{$deleted} kode dihapus. " . ($skipped > 0 ? "{$skipped} kode dilewati (sudah dialokasi/dipakai)." : ''))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            UsagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScrollCodes::route('/'),
            'view'  => Pages\ViewScrollCode::route('/{record}'),
        ];
    }
}
