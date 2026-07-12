<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarrantyResource\Pages;
use App\Filament\Resources\WarrantyResource\RelationManagers;
use App\Exports\WarrantyExport;
use App\Models\Customer;
use App\Models\FilmProduct;
use App\Models\ScrollCode;
use App\Models\Store;
use App\Models\Warranty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class WarrantyResource extends Resource
{
    protected static ?string $model = Warranty::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Garansi';

    protected static ?string $modelLabel = 'Garansi';

    protected static ?string $pluralModelLabel = 'Garansi';

    /**
     * regional_admin (admin toko) hanya melihat garansi milik store-nya,
     * atau data lama yang belum punya store_id. super_admin lihat semua.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->hasRole('super_admin')) {
            $query->where(function (Builder $q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        return $query;
    }

    /**
     * Badge angka merah di sidebar — jumlah QA Certificate yang masih
     * menunggu review, supaya super_admin langsung tahu ada berapa yang
     * perlu ditindak begitu login.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('review_status', 'pending_review')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->hasRole('super_admin');

        return $form->schema([
            Forms\Components\Section::make('Data Garansi')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('warranty_code')
                        ->label('Kode Garansi')
                        ->default(fn () => static::generateWarrantyCode())
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('customer_name')
                        ->label('Nama Pelanggan')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone_number')
                        ->label('No. Telepon')
                        ->tel()
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('customer_id')
                        ->label('Akun Customer (opsional)')
                        ->placeholder('Pilih akun customer jika sudah terdaftar di app')
                        ->options(fn () => Customer::orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Customer $c) => [
                                $c->id => trim(($c->name ?? 'Tanpa Nama') . ' — ' . $c->email),
                            ])
                        )
                        ->searchable()
                        ->nullable()
                        ->columnSpanFull()
                        ->helperText('Garansi akan langsung muncul di "Garansi Saya" pada mobile app customer tersebut.'),

                    Forms\Components\TextInput::make('car_plate')
                        ->label('Plat Nomor')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('car_type')
                        ->label('Tipe Mobil')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('product_series')
                        ->label('Seri Produk')
                        ->placeholder('Contoh: A70')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('product_category')
                        ->label('Kategori Produk')
                        ->options([
                            'window_film' => 'Window Film',
                            'ppf'         => 'PPF',
                        ])
                        ->live()
                        ->required(),

                    Forms\Components\TextInput::make('vin')
                        ->label('VIN (Nomor Rangka)')
                        ->helperText('Berbeda dari plat nomor — VIN permanen, penting untuk garansi jangka panjang.')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('dealer_name')
                        ->label('Nama Dealer (teks bebas)')
                        ->required()
                        ->maxLength(255),

                    // Hanya super_admin yang bisa pindah-pindahkan garansi
                    // antar store. Admin toko otomatis ke store miliknya.
                    Forms\Components\Select::make('store_id')
                        ->label('Toko/Dealer')
                        ->relationship('store', 'name')
                        ->searchable()
                        ->preload()
                        ->visible($isSuperAdmin)
                        ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id),

                    Forms\Components\DatePicker::make('installation_date')
                        ->label('Tanggal Pasang')
                        ->required(),

                    Forms\Components\DatePicker::make('expiry_date')
                        ->label('Tanggal Berakhir')
                        ->required(),
                ]),

            // Field kondisional berdasarkan kategori produk — supaya
            // staff toko tidak diminta isi field yang tidak relevan.
            // PPF: installation_position pilihan tetap (Seluruh Bodi/
            // Parsial) sesuai sertifikat China (装贴部位: [ ] 整车 [ ] 局部),
            // dengan keterangan detail area kalau Parsial dipilih.
            // Window Film: roll number & tipe film DIPISAH untuk Kaca
            // Depan vs Kaca Samping & Belakang, karena biasanya pakai
            // roll film yang berbeda (卷芯号 前挡 vs 侧后).
            Forms\Components\Section::make('Detail Instalasi (PPF)')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('installation_position')
                        ->label('Posisi Pemasangan')
                        ->options([
                            'full_body' => 'Seluruh Bodi',
                            'partial' => 'Parsial (Bagian Tertentu)',
                        ])
                        ->live()
                        ->helperText('Area mobil yang dilapisi PPF.'),

                    Forms\Components\TextInput::make('installation_position_detail')
                        ->label('Keterangan Area')
                        ->placeholder('Contoh: Bumper Depan, Kap Mesin, Fender')
                        ->visible(fn (Forms\Get $get) => $get('installation_position') === 'partial'),

                    Forms\Components\Select::make('roll_number')
                        ->label('Kode Gulungan')
                        ->placeholder('Pilih atau ketik kode dari gulungan fisik')
                        ->options(fn (?Warranty $record) => ScrollCode::query()
                            ->where(fn ($q) => $q
                                ->where('status', 'allocated')
                                ->orWhere('code', $record?->roll_number)
                            )
                            ->pluck('code', 'code')
                        )
                        ->searchable()
                        ->helperText('Pilih kode gulungan PPF yang digunakan. Kode akan otomatis ditandai terpakai.'),
                ])
                ->visible(fn (Forms\Get $get) => $get('product_category') === 'ppf'),

            Forms\Components\Section::make('Detail Instalasi (Window Film)')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('roll_number_front')
                        ->label('Kode Gulungan — Kaca Depan')
                        ->placeholder('Pilih kode gulungan kaca depan')
                        ->options(fn (?Warranty $record) => ScrollCode::query()
                            ->where(fn ($q) => $q
                                ->where('status', 'allocated')
                                ->orWhere('code', $record?->roll_number_front)
                            )
                            ->pluck('code', 'code')
                        )
                        ->searchable(),

                    Forms\Components\Select::make('roll_number_side_rear')
                        ->label('Kode Gulungan — Kaca Samping & Belakang')
                        ->placeholder('Pilih kode gulungan kaca samping & belakang')
                        ->options(fn (?Warranty $record) => ScrollCode::query()
                            ->where(fn ($q) => $q
                                ->where('status', 'allocated')
                                ->orWhere('code', $record?->roll_number_side_rear)
                            )
                            ->pluck('code', 'code')
                        )
                        ->searchable(),

                    Forms\Components\Select::make('film_model_front')
                        ->label('Tipe Film — Kaca Depan')
                        ->placeholder('Pilih seri film kaca depan')
                        ->options(fn () => FilmProduct::where('is_active', true)
                            ->where('product_type', 'window_film')
                            ->where('position', 'front')
                            ->pluck('name', 'name')
                        )
                        ->searchable(),

                    Forms\Components\Select::make('film_model_side_rear')
                        ->label('Tipe Film — Kaca Samping & Belakang')
                        ->placeholder('Pilih seri film kaca samping & belakang')
                        ->options(fn () => FilmProduct::where('is_active', true)
                            ->where('product_type', 'window_film')
                            ->where('position', 'side_rear')
                            ->pluck('name', 'name')
                        )
                        ->searchable(),
                ])
                ->visible(fn (Forms\Get $get) => $get('product_category') === 'window_film'),

            // Section QA Certificate review — hanya ditampilkan sebagai
            // info (read-only) di form. Aksi approve/reject yang
            // sebenarnya dilakukan lewat tombol di tabel/halaman edit
            // (lihat getHeaderActions di EditWarranty), bukan field form,
            // supaya tidak bisa "diam-diam" diubah lewat save form biasa.
            Forms\Components\Section::make('Status Review (QA Certificate)')
                ->columns(2)
                ->schema([
                    Forms\Components\Placeholder::make('review_status')
                        ->label('Status Review')
                        ->content(fn (?Warranty $record) => match ($record?->review_status) {
                            'approved' => 'Approved',
                            'rejected' => 'Rejected',
                            default => 'Pending Review',
                        }),

                    Forms\Components\Placeholder::make('reviewed_at')
                        ->label('Direview Pada')
                        ->content(fn (?Warranty $record) => $record?->reviewed_at?->format('d M Y H:i') ?? '—'),

                    Forms\Components\Placeholder::make('rejection_reason')
                        ->label('Alasan Reject')
                        ->content(fn (?Warranty $record) => $record?->rejection_reason ?? '—')
                        ->columnSpanFull()
                        ->visible(fn (?Warranty $record) => $record?->review_status === 'rejected'),
                ])
                ->visible(fn (?Warranty $record) => $record !== null),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('warranty_code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable(),

                Tables\Columns\TextColumn::make('customer.email')
                    ->label('Akun App')
                    ->placeholder('—')
                    ->badge()
                    ->color('info')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('car_plate')
                    ->label('Plat Nomor')
                    ->searchable(),

                Tables\Columns\TextColumn::make('product_series')
                    ->label('Produk')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('product_category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->colors([
                        'info'    => 'window_film',
                        'success' => 'ppf',
                    ])
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'window_film' => 'Window Film',
                        'ppf'         => 'PPF',
                        default       => '—',
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('phone_number')
                    ->label('No. Telepon')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('review_status')
                    ->label('Review QA')
                    ->colors([
                        'warning' => 'pending_review',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending_review' => 'Pending Review',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        default => $state,
                    }),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status Garansi')
                    ->colors([
                        'success' => 'active',
                        'danger' => 'expired',
                        'warning' => fn ($state) => in_array($state, ['pending', 'pending_review', 'rejected']),
                    ]),

                Tables\Columns\TextColumn::make('expiry_date')
                    ->label('Berakhir')
                    ->date('d M Y')
                    ->sortable()
                    ->description(fn ($record) => $record->extension_years > 0
                        ? "+{$record->extension_years} thn diperpanjang"
                        : null),

                Tables\Columns\TextColumn::make('remaining_days')
                    ->label('Sisa Hari')
                    ->sortable(false),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                // Export Excel untuk dikirim manual (email) ke tim Ginnva
                // China secara mingguan/bulanan. Ini PENGGANTI mekanisme
                // sync API realtime yang sebelumnya direncanakan (job
                // SyncWarrantyToChina) — per info resmi China (akhir Juni
                // 2026), mereka belum bisa sediakan API/data interface
                // karena ketentuan pemerintah. Hanya super_admin yang bisa
                // export, karena ini data sensitif (info pelanggan
                // lengkap) yang dikirim ke pihak luar.
                Tables\Actions\Action::make('exportExcel')
                    ->label('Export ke Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false)
                    ->form([
                        Forms\Components\DatePicker::make('start_date')
                            ->label('Dari Tanggal (opsional)')
                            ->helperText('Kosongkan untuk export semua data dari awal.'),

                        Forms\Components\DatePicker::make('end_date')
                            ->label('Sampai Tanggal (opsional)')
                            ->helperText('Kosongkan untuk export sampai data terbaru.'),
                    ])
                    ->action(function (array $data) {
                        $filename = 'warranty-export-' . now()->format('Ymd-His') . '.xlsx';

                        return Excel::download(
                            new WarrantyExport($data['start_date'] ?? null, $data['end_date'] ?? null),
                            $filename
                        );
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('review_status')
                    ->label('Status Review QA')
                    ->options([
                        'pending_review' => 'Pending Review',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status Garansi')
                    ->options([
                        'active' => 'Active',
                        'expired' => 'Expired',
                        'pending' => 'Pending',
                    ]),

                Tables\Filters\SelectFilter::make('product_category')
                    ->label('Kategori Produk')
                    ->options([
                        'window_film' => 'Window Film',
                        'ppf'         => 'PPF',
                    ]),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->hasRole('super_admin')),

                Tables\Filters\Filter::make('created_at')
                    ->label('Rentang Tanggal Diajukan')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                // Approve/Reject hanya untuk super_admin, sesuai keputusan
                // akses QA review. Muncul hanya kalau masih pending_review.
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Warranty $record) => auth()->user()?->hasRole('super_admin') && $record->review_status === 'pending_review')
                    ->requiresConfirmation()
                    ->action(function (Warranty $record) {
                        $record->update([
                            'review_status' => 'approved',
                            'rejection_reason' => null,
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Garansi disetujui')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Warranty $record) => auth()->user()?->hasRole('super_admin') && $record->review_status === 'pending_review')
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('Alasan Reject')
                            ->required(),
                    ])
                    ->action(function (Warranty $record, array $data) {
                        $record->update([
                            'review_status' => 'rejected',
                            'rejection_reason' => $data['rejection_reason'],
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Garansi ditolak')
                            ->warning()
                            ->send();
                    }),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
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
            'index'  => Pages\ListWarranties::route('/'),
            'create' => Pages\CreateWarranty::route('/create'),
            'view'   => Pages\ViewWarranty::route('/{record}'),
            'edit'   => Pages\EditWarranty::route('/{record}/edit'),
        ];
    }

    public static function generateWarrantyCode(): string
    {
        do {
            $candidate = 'GNV-' . now()->format('Y') . '-' . str_pad(
                random_int(1, 99999), 5, '0', STR_PAD_LEFT
            );
        } while (static::getModel()::where('warranty_code', $candidate)->exists());

        return $candidate;
    }
}