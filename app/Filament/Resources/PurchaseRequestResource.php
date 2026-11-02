<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseRequestResource\Pages;
use App\Models\ChartOfAccount;
use App\Models\ConsumableItem;
use App\Models\PurchaseRequest;
use App\Models\RawMaterial;
use App\Models\Store;
use App\Services\PayableService;
use App\Services\PurchaseRequestPostingService;
use App\Services\PushNotificationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseRequestResource extends Resource
{
    protected static ?string $model = PurchaseRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $cluster = \App\Filament\Clusters\InventarisCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- "Permintaan & Memo".
    protected static ?string $navigationGroup = 'Permintaan & Memo';

    protected static ?string $navigationLabel = 'Permohonan Pembelian';

    protected static ?string $modelLabel = 'Permohonan Pembelian';

    protected static ?string $pluralModelLabel = 'Permohonan Pembelian';

    protected static ?int $navigationSort = 90;

    /**
     * Permohonan memuat biaya aktual pembelian, jadi tidak terbuka lewat akses bawaan akun baru (menu_access kosong): hanya akses penuh
     * atau staf yang menunya dicentang eksplisit (audit Permohonan Pembelian, 2026-11-02). Mengajukan butuh izin Tambah, mengubah
     * (selagi menunggu) butuh izin Ubah, melihat biaya aktual butuh izin Lihat. Setujui/Tolak tetap hanya akses penuh; pemohon selalu
     * boleh membatalkan permohonannya sendiri selagi menunggu.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canSeeCost(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isFullAccess() || $user->hasModuleAction(static::class, 'view', false));
    }

    /**
     * SEBELUMNYA tidak ada override sama sekali di sini dan tidak ada
     * PurchaseRequestPolicy terdaftar — canCreate()/canEdit()/canDelete()
     * bawaan Resource selalu FALSE untuk siapa pun tanpa policy
     * (default-deny Laravel). Sama bug class dengan Resource lain di
     * modul Inventaris (audit-audit sebelumnya). Akibatnya CreateAction,
     * EditAction (sudah ada guard ->visible(status==='pending')), dan
     * DeleteAction (sudah ada guard ->visible(isFullAccess() &&
     * status==='pending')) semuanya tidak pernah muncul. Aksi
     * approve/reject/fulfill AMAN dari bug ini — semuanya Action custom
     * yang di-gate manual lewat ->visible() inline, bukan lewat
     * canEdit()/canDelete() bawaan Filament.
     *
     * canCreate()/canEdit() dibiarkan seluas canViewAny() — tidak ada
     * ->visible() tambahan untuk Create, dan guard status di EditAction
     * sudah cukup sebagai pembatas sendiri. canDelete() dibatasi
     * isFullAccess() (menyamai guard ->visible() yang SUDAH ada eksplisit
     * di DeleteAction).
     */
    public static function canCreate(): bool
    {
        return static::canViewAny() && auth()->user()->hasModuleAction(static::class, 'create', false);
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny()
            && auth()->user()->hasModuleAction(static::class, 'update', false)
            && (($record?->status ?? 'pending') === 'pending');
    }

    /**
     * hasModuleAction(..., false) (audit Majoo f64, 2026-09-23) —
     * default FALSE (tetap ketat spt sebelumnya, isFullAccess()-only).
     */
    public static function canDelete($record): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || (static::canViewAny() && $user->hasModuleAction(static::class, 'delete', false));
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
        $isSuperAdmin = auth()->user()?->isFullAccess();

        return $form->schema([
            Forms\Components\Section::make('Detail Permohonan')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('store_id')
                        ->label('Toko / Workshop')
                        ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id)
                        ->disabled(! $isSuperAdmin)
                        ->dehydrated(),

                    Forms\Components\Select::make('item_type')
                        ->label('Jenis Barang')
                        ->options([
                            'raw_material'    => 'Bahan Baku (restock)',
                            'consumable_item' => 'Barang Habis Pakai (restock)',
                            'asset'           => 'Aset Baru (belum ada di katalog)',
                        ])
                        ->in(['raw_material', 'consumable_item', 'asset'])
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('item_id', null)),

                    // Bahan Baku/Barang Habis Pakai: pilih dari katalog yang
                    // sudah ada (restock) — nama & satuan diambil OTOMATIS
                    // dari baris katalognya saat disimpan (lihat
                    // CreatePurchaseRequest), bukan diketik manual, supaya
                    // tidak mungkin typo beda dari nama aslinya di stok.
                    Forms\Components\Select::make('item_id')
                        ->label('Barang')
                        ->visible(fn (Forms\Get $get) => in_array($get('item_type'), ['raw_material', 'consumable_item']))
                        ->required(fn (Forms\Get $get) => in_array($get('item_type'), ['raw_material', 'consumable_item']))
                        ->searchable()
                        ->options(fn (Forms\Get $get) => match ($get('item_type')) {
                            'raw_material'    => RawMaterial::active()->orderBy('name')->pluck('name', 'id'),
                            'consumable_item' => ConsumableItem::active()->orderBy('name')->pluck('name', 'id'),
                            default           => [],
                        })
                        ->in(fn (Forms\Get $get, ?PurchaseRequest $record) => static::allowedItemIds($get('item_type'), $record))
                        // Satu barang satu permohonan terbuka (menunggu/disetujui), sama dengan tombol Minta Beli dan pengajuan dari mobile.
                        ->rule(fn (Forms\Get $get, ?PurchaseRequest $record) => function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                            $type = $get('item_type');

                            if (! in_array($type, ['raw_material', 'consumable_item'], true) || ! filled($value)) {
                                return;
                            }

                            $open = PurchaseRequest::withoutGlobalScopes()
                                ->where('item_type', $type)
                                ->where('item_id', $value)
                                ->whereIn('status', ['pending', 'approved'])
                                ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                                ->first();

                            if ($open) {
                                $fail("Sudah ada permohonan untuk barang ini ({$open->request_number}, status " . ($open->status === 'approved' ? 'disetujui' : 'menunggu') . ').');
                            }
                        }),

                    // Aset baru: belum ada baris katalognya, jadi nama
                    // diketik manual di sini.
                    Forms\Components\TextInput::make('item_name')
                        ->label('Nama Barang / Aset yang Diminta')
                        ->visible(fn (Forms\Get $get) => $get('item_type') === 'asset')
                        ->required(fn (Forms\Get $get) => $get('item_type') === 'asset')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('quantity')
                        ->label('Jumlah')
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->default(1),

                    Forms\Components\Textarea::make('reason')
                        ->label('Alasan / Catatan')
                        ->placeholder('Contoh: stok tinggal 2 liter, dipakai rata-rata 5 liter/minggu')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
            // Hanya di halaman Lihat/Ubah: riwayat pemrosesan permohonan.
            Forms\Components\Section::make('Proses Permohonan')
                ->columns(2)
                ->visible(fn (?PurchaseRequest $record) => $record !== null)
                ->schema([
                    Forms\Components\Placeholder::make('p_status')->label('Status')
                        ->content(fn (?PurchaseRequest $record) => static::statusLabel((string) $record?->status)),
                    Forms\Components\Placeholder::make('p_requester')->label('Diajukan Oleh')
                        ->content(fn (?PurchaseRequest $record) => ($record?->requester?->name ?? '—') . ($record?->created_at ? ' · ' . $record->created_at->format('d M Y H:i') : '')),
                    Forms\Components\Placeholder::make('p_reviewer')->label('Ditinjau Oleh')
                        ->content(fn (?PurchaseRequest $record) => $record?->reviewer ? $record->reviewer->name . ($record->reviewed_at ? ' · ' . $record->reviewed_at->format('d M Y H:i') : '') : '—'),
                    Forms\Components\Placeholder::make('p_note')->label('Catatan Peninjau')
                        ->content(fn (?PurchaseRequest $record) => $record?->review_note ?: '—'),
                    Forms\Components\Placeholder::make('p_approved')->label('Jumlah Disetujui')
                        ->content(fn (?PurchaseRequest $record) => $record === null || $record->quantity_approved === null
                            ? ($record?->status === 'pending' ? 'Belum ditinjau' : 'Sama dengan yang diminta')
                            : rtrim(rtrim(number_format((float) $record->quantity_approved, 2, ',', '.'), '0'), ',') . ($record->unit ? " {$record->unit}" : '')),
                    Forms\Components\Placeholder::make('p_received')->label('Jumlah Diterima')
                        ->content(fn (?PurchaseRequest $record) => $record?->quantity_received === null
                            ? '—'
                            : rtrim(rtrim(number_format((float) $record->quantity_received, 2, ',', '.'), '0'), ',') . ($record->unit ? " {$record->unit}" : '')
                                . ($record->fulfilled_at ? ' · ' . $record->fulfilled_at->format('d M Y') : '')),
                    Forms\Components\Placeholder::make('p_cost')->label('Biaya Aktual')
                        ->visible(fn () => static::canSeeCost())
                        ->content(fn (?PurchaseRequest $record) => $record?->actual_cost === null ? '—' : 'Rp ' . number_format((float) $record->actual_cost, 0, ',', '.')),
                    Forms\Components\Placeholder::make('p_recorded')->label('Tercatat ke Stok/Aset')
                        ->content(fn (?PurchaseRequest $record) => $record?->stock_recorded_at ? 'Ya, ' . $record->stock_recorded_at->format('d M Y H:i') : 'Belum'),
                ]),
        ]);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending'   => 'Menunggu Persetujuan',
            'approved'  => 'Disetujui',
            'rejected'  => 'Ditolak',
            'fulfilled' => 'Terpenuhi',
            'cancelled' => 'Dibatalkan',
            default     => $status,
        };
    }

    /** Id barang yang boleh dipilih: yang aktif, ditambah barang yang sudah tercatat di permohonan ini (supaya permohonan lama tetap bisa disimpan). */
    public static function allowedItemIds(?string $type, ?PurchaseRequest $record): array
    {
        $ids = match ($type) {
            'raw_material'    => RawMaterial::active()->pluck('id')->all(),
            'consumable_item' => ConsumableItem::active()->pluck('id')->all(),
            default           => [],
        };

        if ($record !== null && $record->item_type === $type && $record->item_id !== null) {
            $ids[] = (int) $record->item_id;
        }

        return $ids;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('request_number')
                    ->label('No. Permohonan')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('item_name')
                    ->label('Barang')
                    ->searchable()
                    ->description(fn (PurchaseRequest $record) => match ($record->item_type) {
                        'raw_material'    => 'Bahan Baku',
                        'consumable_item' => 'Barang Habis Pakai',
                        'asset'           => 'Aset Baru',
                        default           => null,
                    }),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Jumlah')
                    ->formatStateUsing(fn (PurchaseRequest $record) => rtrim(rtrim(number_format((float) $record->quantity, 2, ',', '.'), '0'), ',') . ($record->unit ? " {$record->unit}" : ''))
                    ->description(fn (PurchaseRequest $record) => $record->quantity_approved !== null
                        ? 'Disetujui ' . rtrim(rtrim(number_format((float) $record->quantity_approved, 2, ',', '.'), '0'), ',') . ($record->unit ? " {$record->unit}" : '')
                        : null),

                Tables\Columns\IconColumn::make('stock_recorded_at')
                    ->label('Tercatat (Stok/Aset)')
                    ->boolean()
                    ->getStateUsing(fn (PurchaseRequest $record) => $record->stock_recorded_at !== null)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('quantity_received')
                    ->label('Diterima')
                    ->formatStateUsing(fn (PurchaseRequest $record) => $record->quantity_received === null
                        ? null
                        : rtrim(rtrim(number_format((float) $record->quantity_received, 2, ',', '.'), '0'), ',') . ' ' . $record->unit)
                    ->placeholder('—')
                    ->color(fn (PurchaseRequest $record) => $record->shortfall() !== null ? 'warning' : null)
                    ->description(fn (PurchaseRequest $record) => $record->shortfall() !== null
                        ? 'Kurang ' . rtrim(rtrim(number_format($record->shortfall(), 2, ',', '.'), '0'), ',') . ' ' . $record->unit
                        : null),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger'  => 'rejected',
                        'gray'    => ['fulfilled', 'cancelled'],
                    ])
                    ->formatStateUsing(fn (string $state): string => static::statusLabel($state)),

                Tables\Columns\TextColumn::make('actual_cost')
                    ->label('Biaya Aktual')
                    ->visible(fn () => static::canSeeCost())
                    ->money('IDR', locale: 'id')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('requester.name')
                    ->label('Diajukan Oleh')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'   => 'Menunggu Persetujuan',
                        'approved'  => 'Disetujui',
                        'rejected'  => 'Ditolak',
                        'fulfilled' => 'Terpenuhi',
                        'cancelled' => 'Dibatalkan',
                    ]),

                Tables\Filters\Filter::make('mine')
                    ->label('Permohonan Saya')
                    ->query(fn (Builder $query) => $query->where('requested_by', auth()->id())),

                Tables\Filters\Filter::make('created_between')
                    ->label('Tanggal Diajukan')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))),

                // Dibandingkan dengan jumlah yang berlaku (yang disetujui bila diturunkan, selain itu yang diminta).
                Tables\Filters\Filter::make('short_received')
                    ->label('Diterima kurang dari diminta')
                    ->query(fn (Builder $query) => $query->whereNotNull('quantity_received')->whereRaw('quantity_received < COALESCE(quantity_approved, quantity)')),

                Tables\Filters\SelectFilter::make('item_type')
                    ->label('Jenis Barang')
                    ->options([
                        'raw_material'    => 'Bahan Baku',
                        'consumable_item' => 'Barang Habis Pakai',
                        'asset'           => 'Aset Baru',
                    ]),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (PurchaseRequest $record) => $record->status === 'pending'
                        && (auth()->user()?->isFullAccess() || auth()->id() === $record->requested_by))
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan permohonan ini?')
                    ->modalDescription('Permohonan yang dibatalkan tidak diproses lagi. Ajukan permohonan baru bila barangnya masih diperlukan.')
                    ->action(function (PurchaseRequest $record) {
                        $processed = DB::transaction(function () use ($record) {
                            $locked = PurchaseRequest::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

                            if ($locked->status !== 'pending') {
                                return true;
                            }

                            $locked->update(['status' => 'cancelled']);

                            return false;
                        });

                        if ($processed) {
                            Notification::make()->title('Permohonan sudah diproses')->warning()->send();

                            return;
                        }

                        Notification::make()->title('Permohonan dibatalkan')->success()->send();
                    }),

                Tables\Actions\Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (PurchaseRequest $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'pending')
                    ->modalHeading(fn (PurchaseRequest $record) => "Setujui {$record->request_number}?")
                    ->modalDescription('Jumlah yang disetujui boleh lebih kecil dari yang diminta; jumlah awal tetap tercatat.')
                    ->form(fn (PurchaseRequest $record) => [
                        Forms\Components\TextInput::make('quantity_approved')
                            ->label('Jumlah yang Disetujui')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->maxValue((float) $record->quantity)
                            ->default((float) $record->quantity)
                            ->suffix($record->unit)
                            ->helperText('Diminta: ' . rtrim(rtrim(number_format((float) $record->quantity, 2, ',', '.'), '0'), ',') . ($record->unit ? " {$record->unit}" : '') . '.'),
                    ])
                    ->action(function (PurchaseRequest $record, array $data) {
                        // Kunci + cek ulang status: $record dari Livewire bisa basi (klik ganda / dua reviewer bersamaan).
                        $processed = DB::transaction(function () use ($record, $data) {
                            $locked = PurchaseRequest::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

                            if ($locked->status !== 'pending') {
                                return true;
                            }

                            $locked->update([
                                'status'      => 'approved',
                                // Kosong bila disetujui sebesar yang diminta.
                                'quantity_approved' => round((float) $data['quantity_approved'], 2) < round((float) $locked->quantity, 2) ? round((float) $data['quantity_approved'], 2) : null,
                                'reviewed_by' => auth()->id(),
                                'reviewed_at' => now(),
                            ]);

                            return false;
                        });

                        if ($processed) {
                            Notification::make()->title('Permohonan sudah diproses')->warning()->send();

                            return;
                        }

                        $record->refresh();

                        if ($record->requested_by) {
                            app(\App\Services\StaffNotifier::class)->toUsers(
                                [$record->requested_by],
                                'Permohonan Pembelian Disetujui',
                                "Permohonan {$record->request_number} ({$record->item_name}) disetujui" . ($record->quantity_approved !== null ? ' dengan jumlah ' . rtrim(rtrim(number_format((float) $record->quantity_approved, 2, ',', '.'), '0'), ',') . ($record->unit ? " {$record->unit}" : '') . ' (diminta ' . rtrim(rtrim(number_format((float) $record->quantity, 2, ',', '.'), '0'), ',') . ').' : '.')
                            );
                        }

                        Notification::make()->title('Permohonan disetujui')->success()->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PurchaseRequest $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'pending')
                    ->form([
                        Forms\Components\Textarea::make('review_note')
                            ->label('Alasan Ditolak')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (PurchaseRequest $record, array $data) {
                        $processed = DB::transaction(function () use ($record, $data) {
                            $locked = PurchaseRequest::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

                            if ($locked->status !== 'pending') {
                                return true;
                            }

                            $locked->update([
                                'status'      => 'rejected',
                                'reviewed_by' => auth()->id(),
                                'reviewed_at' => now(),
                                'review_note' => $data['review_note'],
                            ]);

                            return false;
                        });

                        if ($processed) {
                            Notification::make()->title('Permohonan sudah diproses')->warning()->send();

                            return;
                        }

                        $record->refresh();

                        if ($record->requested_by) {
                            app(\App\Services\StaffNotifier::class)->toUsers(
                                [$record->requested_by],
                                'Permohonan Pembelian Ditolak',
                                "Permohonan {$record->request_number} ({$record->item_name}) ditolak: {$data['review_note']}"
                            );
                        }

                        Notification::make()->title('Permohonan ditolak')->warning()->send();
                    }),

                // Sengaja TIDAK bikin movement stok otomatis di sini — staff
                // tetap catat stok masuk lewat "Catat Stok" yang sudah ada
                // begitu barang benar-benar sampai, baru tandai Terpenuhi
                // (lihat catatan migration create_purchase_requests_table).
                Tables\Actions\Action::make('fulfill')
                    ->label('Tandai Terpenuhi')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->color('gray')
                    ->visible(fn (PurchaseRequest $record) => $record->status === 'approved'
                        && (auth()->user()?->isFullAccess() || auth()->id() === $record->requested_by))
                    ->form([
                        // Jumlah yang benar-benar sampai (audit ACCURATE 5): terlihat di daftar sebagai Diterima dan Kurang.
                        Forms\Components\TextInput::make('quantity_received')
                            ->label('Jumlah Diterima')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->default(fn (PurchaseRequest $record) => $record->effectiveQuantity())
                            ->suffix(fn (PurchaseRequest $record) => $record->unit)
                            ->helperText('Isi sesuai barang yang benar-benar sampai. Bila kurang dari yang diminta, ajukan permohonan baru untuk sisanya.'),

                        // Penerimaan sekaligus jadi stok masuk (batch baru), supaya "terpenuhi" tidak berbeda dengan stok di gudang.
                        // Hanya bagi yang memang boleh mengubah stok barang itu; matikan bila barangnya sudah dicatat lewat Catat Stok.
                        Forms\Components\Toggle::make('record_stock')
                            ->label('Catat sebagai stok masuk sekarang')
                            ->default(fn (PurchaseRequest $record) => static::canRecordStock($record))
                            ->visible(fn (PurchaseRequest $record) => static::canRecordStock($record))
                            ->live()
                            ->helperText('Stok bertambah sebesar Jumlah Diterima sebagai batch baru (harga per satuan dari Total Biaya Aktual bila diisi). Matikan kalau barang ini sudah dicatat masuk lewat Catat Stok, supaya tidak tercatat dua kali.'),

                        Forms\Components\DatePicker::make('expiry_date')
                            ->label('Tanggal Kedaluwarsa Batch Ini (opsional)')
                            ->native(false)
                            ->displayFormat('d M Y')
                            ->visible(fn (PurchaseRequest $record, Forms\Get $get) => $record->item_type === 'raw_material'
                                && static::canRecordStock($record)
                                && (bool) $get('record_stock')),

                        // actual_cost BARU diisi di sini (bukan saat
                        // permohonan diajukan) — harga aktual baru pasti
                        // setelah barang benar-benar dibeli, dipakai
                        // PurchaseRequestPostingService untuk jurnal
                        // Persediaan/Aset otomatis.
                        // Permohonan Aset Baru: terima barangnya sekaligus daftarkan ke Aset Tetap (satu aset per unit, kode QR masing-masing).
                        Forms\Components\Toggle::make('register_asset')
                            ->label('Daftarkan sebagai Aset Tetap sekarang')
                            ->default(fn (PurchaseRequest $record) => static::canRegisterAsset($record))
                            ->visible(fn (PurchaseRequest $record) => static::canRegisterAsset($record))
                            ->helperText('Satu aset per unit yang diterima, dengan harga dari Total Biaya Aktual dibagi rata. Matikan kalau aset ini sudah didaftarkan manual, supaya tidak tercatat dua kali.'),

                        Forms\Components\TextInput::make('actual_cost')
                            ->label('Total Biaya Aktual')
                            ->numeric()
                            ->required(fn () => (bool) config('features.finance'))
                            ->minValue(0.01)
                            ->prefix('Rp')
                            ->helperText(fn () => config('features.finance')
                                ? 'Dipakai untuk mencatat jurnal Persediaan/Aset otomatis (Kredit Hutang Usaha).'
                                : 'Opsional. Hanya catatan; pembukuan dilakukan di Accurate.'),

                        Forms\Components\Select::make('supplier_id')
                            ->label('Supplier')
                            ->options(fn () => \App\Models\Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                            ->default(function (PurchaseRequest $record) {
                                $preferred = $record->resolveItem()?->preferredSupplier;

                                return $preferred?->is_active ? $preferred->id : null;
                            })
                            ->searchable()
                            ->required(fn () => (bool) config('features.finance'))
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')->label('Nama Supplier')->required()->maxLength(255),
                            ])
                            ->createOptionUsing(fn (array $data) => \App\Models\Supplier::findOrCreateByName($data['name'], $data)->getKey())
                            ->helperText('Dipakai untuk mencatat tagihan ini di menu Hutang Usaha. Supplier baru bisa langsung dibuat di sini.'),

                        Forms\Components\DatePicker::make('due_date')
                            ->label('Jatuh Tempo (opsional)')
                            ->visible(fn () => (bool) config('features.finance'))
                            ->native(false),

                        Forms\Components\Select::make('chart_of_account_id')
                            ->label('Akun Aset Tetap Tujuan')
                            ->visible(fn (PurchaseRequest $record) => config('features.finance') && $record->item_type === 'asset')
                            ->required(fn (PurchaseRequest $record) => config('features.finance') && $record->item_type === 'asset')
                            ->options(fn () => ChartOfAccount::whereHas('parent', fn ($q) => $q->where('code', '1200'))
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                            ->searchable()
                            ->helperText('Permohonan jenis "Aset Baru" belum punya baris Aset tersendiri — pilih akun Aset Tetap yang sesuai secara manual.'),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Pastikan barang sudah dicatat masuk lewat "Catat Stok" sebelum menandai ini terpenuhi.')
                    ->action(function (PurchaseRequest $record, array $data) {
                        try {
                            DB::transaction(function () use ($record, $data) {
                                // Kunci baris + cek ulang status di dalam transaksi (audit Hutang
                                // Usaha 2026-09-29): $record dari Livewire bisa basi, klik ganda /
                                // 2 orang bersamaan sebelumnya bisa bikin jurnal & Payable dobel.
                                $locked = PurchaseRequest::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

                                if ($locked->status !== 'approved') {
                                    throw new RuntimeException('Permohonan ini sudah diproses (status: ' . $locked->status . ').');
                                }

                                $ledger = (bool) config('features.finance');
                                $supplier = ! empty($data['supplier_id']) ? \App\Models\Supplier::findOrFail($data['supplier_id']) : null;

                                $locked->update([
                                    'status'       => 'fulfilled',
                                    'fulfilled_at' => now(),
                                    'quantity_received' => $data['quantity_received'],
                                    'actual_cost'  => $data['actual_cost'] ?? null,
                                ]);

                                // Stok masuk sejumlah yang diterima, dalam transaksi yang sama: status terpenuhi dan stok selalu sejalan.
                                if (! empty($data['record_stock']) && static::canRecordStock($locked)) {
                                    $item = $locked->resolveItem();
                                    $received = (float) $data['quantity_received'];
                                    $unitCost = ! empty($data['actual_cost']) && $received > 0 ? round((float) $data['actual_cost'] / $received, 2) : null;
                                    $note = "Permohonan {$locked->request_number} — barang diterima";

                                    if ($item instanceof \App\Models\RawMaterial) {
                                        $item->recordMovement('in', $received, auth()->id(), $note, null, ! empty($data['expiry_date']) ? (string) $data['expiry_date'] : null, $unitCost, $locked->store_id);
                                    } else {
                                        $item->recordMovement('in', $received, auth()->id(), $note, $unitCost, $locked->store_id);
                                    }

                                    $locked->update(['stock_recorded_at' => now()]);
                                }

                                if (! empty($data['register_asset']) && static::canRegisterAsset($locked)) {
                                    $units = max(1, min(50, (int) floor((float) $data['quantity_received'])));
                                    $unitCost = ! empty($data['actual_cost']) ? round((float) $data['actual_cost'] / $units, 2) : null;

                                    for ($i = 1; $i <= $units; $i++) {
                                        \App\Models\Asset::create([
                                            'asset_tag' => \App\Models\Asset::generateAssetTag(),
                                            'name' => $units > 1 ? "{$locked->item_name} #{$i}" : $locked->item_name,
                                            'status' => 'aktif',
                                            'store_id' => $locked->store_id,
                                            'received_date' => today()->toDateString(),
                                            'purchase_date' => today()->toDateString(),
                                            'purchase_cost' => $unitCost,
                                            'notes' => "Dari permohonan pembelian {$locked->request_number}",
                                            'created_by' => auth()->id(),
                                        ]);
                                    }

                                    $locked->update(['stock_recorded_at' => now()]);
                                }

                                // Pembukuan dipegang Accurate 5 (FEATURE_FINANCE mati): cukup tandai terpenuhi, tanpa jurnal dan tagihan.
                                if (! $ledger) {
                                    return;
                                }

                                $entry = app(PurchaseRequestPostingService::class)->post(
                                    $locked->refresh(),
                                    (float) $data['actual_cost'],
                                    $data['chart_of_account_id'] ?? null
                                );

                                $locked->update(['journal_entry_id' => $entry->id]);

                                // Payable dicatat menautkan ke jurnal yang
                                // BARU SAJA dibuat di atas — TIDAK bikin
                                // jurnal baru lagi (lihat komentar
                                // PayableService::create() vs
                                // createWithJournal()), supaya saldo 2110
                                // tidak dobel tercatat. source_key unik =
                                // lapisan kedua anti-duplikat.
                                app(PayableService::class)->create([
                                    'supplier_name' => $supplier->name,
                                    'supplier_id' => $supplier->id,
                                    'store_id' => $locked->store_id,
                                    'source_type' => 'purchase_request',
                                    'source_id' => $locked->id,
                                    'amount' => $data['actual_cost'],
                                    'due_date' => $data['due_date'] ?? null,
                                    'journal_entry_id' => $entry->id,
                                    'created_by' => auth()->id(),
                                ]);
                            });
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Gagal menandai terpenuhi')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->refresh();

                        if ($record->requested_by) {
                            app(\App\Services\StaffNotifier::class)->toUsers(
                                [$record->requested_by],
                                'Permohonan Pembelian Terpenuhi',
                                "Permohonan {$record->request_number} ({$record->item_name}) sudah terpenuhi, barang sudah tersedia."
                            );
                        }

                        Notification::make()
                            ->title('Permohonan ditandai terpenuhi')
                            ->body($record->stock_recorded_at ? ($record->item_type === 'asset' ? 'Aset sudah didaftarkan ke menu Aset Tetap.' : 'Stok bertambah sesuai jumlah yang diterima.') : null)
                            ->success()
                            ->send();
                    }),

                Tables\Actions\EditAction::make()
                    ->visible(fn (PurchaseRequest $record) => $record->status === 'pending'),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (PurchaseRequest $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'pending'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * "Tandai Terpenuhi" boleh sekaligus mencatat stok masuk bila barangnya masih ada, belum pernah dicatat dari permohonan ini, dan
     * akun ini memang boleh mengubah stok barang itu (Bahan Baku / Barang Habis Pakai), sebab pemohon belum tentu punya izin itu.
     */
    public static function canRecordStock(PurchaseRequest $record): bool
    {
        if ($record->stock_recorded_at !== null || ! in_array($record->item_type, ['raw_material', 'consumable_item'], true)) {
            return false;
        }

        $item = $record->resolveItem();

        if ($item === null) {
            return false;
        }

        return $item instanceof \App\Models\RawMaterial
            ? \App\Filament\Resources\RawMaterialResource::canEdit($item)
            : \App\Filament\Resources\ConsumableItemResource::canEdit($item);
    }

    /** Permohonan Aset Baru yang terpenuhi boleh sekaligus didaftarkan ke Aset Tetap, bila belum dan akun ini boleh menambah aset. */
    public static function canRegisterAsset(PurchaseRequest $record): bool
    {
        return $record->item_type === 'asset'
            && $record->stock_recorded_at === null
            && \App\Filament\Resources\AssetResource::canCreate();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static ?string $navigationBadgeColor = 'warning';

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\RelationManagers\ActivityHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPurchaseRequests::route('/'),
            'create' => Pages\CreatePurchaseRequest::route('/create'),
            'view'   => Pages\ViewPurchaseRequest::route('/{record}'),
            'edit'   => Pages\EditPurchaseRequest::route('/{record}/edit'),
        ];
    }
}
