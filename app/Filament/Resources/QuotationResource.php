<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuotationResource\Pages;
use App\Models\Quotation;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Grid as InfolistGrid;
use Filament\Infolists\Components\Group as InfolistGroup;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $cluster = \App\Filament\Clusters\BookingCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 0-99 "Order & Lead", pola band
    // 100-lebar per grup sama seperti cluster Keuangan/Karyawan/Marketing.
    protected static ?string $navigationGroup = 'Order & Lead';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Quotation (Lead)';

    protected static ?string $modelLabel = 'Quotation';

    protected static ?string $pluralModelLabel = 'Quotation';

    // Sebelumnya tidak ada badge sama sekali di sini — beda dari
    // PartnershipInquiryResource & ProductInquiryResource yang punya pola
    // status 'new' identik, keduanya sudah dikasih badge count. Staff jadi
    // tidak punya sinyal visual kalau ada lead quotation baru yang belum
    // di-follow-up, padahal field status-nya sudah mendukung.
    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('status', 'new')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager-load 'items.filmProduct' — dipakai kolom "Produk Diminati"
        // di tabel (lihat audit UI/UX Filament Quotation 2026-08-27),
        // tanpa ini tiap baris akan N+1.
        $query = parent::getEloquentQuery()->with(['items.filmProduct', 'owner']);

        // Kolom sort virtual — SEBELUMNYA pakai ->defaultSort(Closure),
        // TERNYATA tidak didukung Filament v3 di sini (diam-diam
        // diabaikan, bukan error — baru ketahuan dari screenshot staff
        // yang urutannya ternyata cuma created_at desc polos, plus ada
        // ->defaultSort('created_at','desc') duplikat yang menimpa).
        // Pendekatan ini pasti jalan karena cuma pakai
        // ->defaultSort('sort_priority') dengan 1 kolom+1 arah biasa,
        // API yang sudah confirmed didukung. Lead 'new' dapat rentang
        // angka lebih kecil dari SEMUA status lain (selalu di atas kalau
        // sort ASC), dan di dalam masing-masing grup diurutkan sesuai
        // arah yang diinginkan lewat pembalikan angka. Lihat audit UI/UX
        // Filament Quotation 2026-08-27 (perbaikan susulan).
        //
        // BUG SUSULAN (500 di /admin/quotations): addSelect() TANPA
        // select('*') eksplisit dulu TIDAK otomatis include '*' —
        // ->columns null di-cast (array) jadi [] kosong, bukan ['*'].
        // Akibatnya SELURUH kolom (termasuk `id`) hilang dari hasil
        // query, cuma tersisa sort_priority sendirian — Filament gagal
        // bikin link "View" karena record->getKey() kosong ("Missing
        // parameter: record"). select('quotations.*') dulu secara
        // eksplisit sebelum addSelect() menutup celah ini. Ditemukan &
        // diperbaiki 2026-08-28.
        $query->select('quotations.*')->addSelect(\Illuminate\Support\Facades\DB::raw("
            CASE WHEN quotations.status = 'new'
                THEN UNIX_TIMESTAMP(quotations.created_at)
                ELSE 10000000000 + (9999999999 - UNIX_TIMESTAMP(quotations.created_at))
            END as sort_priority
        "));

        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where(function (Builder $q) use ($user) {
                $q->where('store_id', $user->store_id)
                    ->orWhereNull('store_id');
            });
        }

        return $query;
    }


    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->isFullAccess();

        // Dua kolom di layar lebar: kiri isi lead (pelanggan, kendaraan, produk), kanan penanganan (status, penanggung jawab, hasil).
        return $form->schema([
            Forms\Components\Grid::make(['default' => 1, 'lg' => 3])->schema([
                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Forms\Components\Section::make('Pelanggan')
                            ->icon('heroicon-o-user')
                            ->columns(2)
                            ->schema([
                                Forms\Components\TextInput::make('customer_name')
                                    ->label('Nama Pelanggan')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('customer_phone')
                                    ->label('No. Telepon / WhatsApp')
                                    ->tel()
                                    ->prefixIcon('heroicon-o-phone')
                                    // Disamakan dengan validasi API publik (QuotationController::submit()): telepon dan email wajib
                                    // karena dipakai follow-up (WA/telepon dan konfirmasi email).
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('customer_email')
                                    ->label('Email')
                                    ->email()
                                    ->prefixIcon('heroicon-o-envelope')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('license_plate')
                                    ->label('Plat Nomor')
                                    ->maxLength(255),

                                Forms\Components\Textarea::make('message')
                                    ->label('Catatan / Kebutuhan Pelanggan')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ]),

                        /* Kendaraan (cascading merek, lalu tipe). Label tipe menyertakan varian supaya varian kembar tidak tampak sama. */
                        Forms\Components\Section::make('Kendaraan')
                            ->icon('heroicon-o-truck')
                            ->columns(2)
                            ->schema([
                                Forms\Components\Select::make('vehicle_brand')
                                    ->label('Merek Kendaraan')
                                    ->options(
                                        Vehicle::query()
                                            ->distinct()
                                            ->orderBy('brand')
                                            ->pluck('brand', 'brand')
                                    )
                                    ->live()
                                    ->afterStateUpdated(fn (Set $set) => $set('vehicle_id', null))
                                    ->required()
                                    ->dehydrated(false),  // tidak disimpan ke DB

                                Forms\Components\Select::make('vehicle_id')
                                    ->label('Tipe Kendaraan')
                                    ->options(fn (Get $get) => Vehicle::query()
                                        ->where('brand', $get('vehicle_brand'))
                                        ->orderBy('model')
                                        ->orderBy('variant')
                                        ->get()
                                        ->mapWithKeys(fn ($v) => [
                                            $v->id => $v->variant
                                                ? "{$v->model} — {$v->variant} (Size {$v->size_category})"
                                                : "{$v->model} (Size {$v->size_category})",
                                        ])
                                    )
                                    ->disabled(fn (Get $get) => blank($get('vehicle_brand')))
                                    ->live()
                                    ->required()
                                    ->searchable(),
                            ]),

                        Forms\Components\Section::make('Produk yang Diminati')
                            ->icon('heroicon-o-cube')
                            ->schema([
                                Forms\Components\Repeater::make('items')
                                    ->relationship('items')
                                    ->hiddenLabel()
                                    ->schema([
                                        Forms\Components\Select::make('film_product_id')
                                            ->label('Produk Film')
                                            ->relationship('filmProduct', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->required()
                                            ->columnSpan(2),

                                        Forms\Components\TextInput::make('quantity')
                                            ->label('Jumlah')
                                            ->numeric()
                                            ->default(1)
                                            ->minValue(1)
                                            ->required(),

                                        Forms\Components\Textarea::make('notes')
                                            ->label('Keterangan')
                                            ->placeholder('Contoh: warna preferensi, bagian kendaraan tertentu')
                                            ->rows(2)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(3)
                                    ->addActionLabel('+ Tambah Produk')
                                    ->reorderable(false)
                                    ->defaultItems(1),
                            ]),
                    ]),

                Forms\Components\Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Forms\Components\Section::make('Penanganan')
                            ->icon('heroicon-o-adjustments-horizontal')
                            ->schema([
                                Forms\Components\TextInput::make('quotation_number')
                                    ->label('No. Quotation')
                                    ->default(fn () => static::generateQuotationNumber())
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->disabledOn('edit')
                                    ->maxLength(255),

                                Forms\Components\Select::make('status')
                                    ->label('Status Follow-up')
                                    ->options([
                                        'new'       => 'New',
                                        'contacted' => 'Contacted',
                                        'closed'    => 'Closed',
                                        'cancelled' => 'Cancelled',
                                    ])
                                    ->required()
                                    ->default('new'),

                                Forms\Components\Select::make('owner_user_id')
                                    ->label('Penanggung Jawab')
                                    ->options(fn () => \App\Models\User::query()
                                        ->where('is_active', true)
                                        ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['installer', 'partner']))
                                        ->orderBy('name')
                                        ->pluck('name', 'id'))
                                    ->searchable()
                                    ->placeholder('Belum ditentukan')
                                    ->default(fn (string $operation) => $operation === 'create' ? auth()->id() : null),

                                Forms\Components\Select::make('store_id')
                                    ->label('Toko/Dealer')
                                    ->relationship('store', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->visible($isSuperAdmin)
                                    ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id),

                                // Marketing/Konten Fase 0: lead yang diinput staf wajib menyebut asalnya (lead web/aplikasi terisi otomatis).
                                Forms\Components\Select::make('channel')
                                    ->label('Kanal Masuk')
                                    ->options(array_intersect_key(\App\Support\LeadAttribution::CHANNELS, array_flip(\App\Support\LeadAttribution::MANUAL_CHANNELS)))
                                    ->required()
                                    ->visible(fn (string $operation) => $operation === 'create')
                                    ->helperText('Dari mana lead ini datang (WhatsApp, telepon, datang langsung, dst.).'),
                            ]),

                        Forms\Components\Section::make('Hasil')
                            ->icon('heroicon-o-flag')
                            ->schema([
                                Forms\Components\Select::make('outcome')
                                    ->label('Hasil')
                                    ->options(\App\Support\LeadOutcome::OUTCOMES)
                                    ->placeholder('Belum ada hasil')
                                    ->live()
                                    ->helperText('Deal kalau sudah jadi pelanggan, booking, atau partner. Tidak Jadi kalau batal.'),

                                Forms\Components\Select::make('outcome_reason')
                                    ->label('Alasan Tidak Jadi')
                                    ->options(\App\Support\LeadOutcome::REASONS)
                                    ->live()
                                    ->visible(fn (Forms\Get $get) => $get('outcome') === 'lost')
                                    ->required(fn (Forms\Get $get) => $get('outcome') === 'lost'),

                                Forms\Components\TextInput::make('outcome_note')
                                    ->label('Jelaskan alasannya')
                                    ->maxLength(255)
                                    ->visible(fn (Forms\Get $get) => $get('outcome') === 'lost' && $get('outcome_reason') === 'lainnya')
                                    ->required(fn (Forms\Get $get) => $get('outcome') === 'lost' && $get('outcome_reason') === 'lainnya'),
                            ]),
                    ]),
            ]),
        ]);
    }

    /**
     * Tampilan baca (View): dua kolom seperti form. Kiri: pelanggan, kendaraan, produk. Kanan: status dan hasil, lalu jejak lead
     * (kapan masuk, berapa lama direspons, asal). Telepon dan email bisa disalin; telepon langsung membuka WhatsApp.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        $statusLabel = fn (string $state) => match ($state) {
            'new'       => 'New',
            'contacted' => 'Contacted',
            'closed'    => 'Closed',
            'cancelled' => 'Cancelled',
            default     => $state,
        };

        return $infolist->schema([
            InfolistGrid::make(['default' => 1, 'lg' => 3])->schema([
                InfolistGroup::make()
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        InfolistSection::make('Pelanggan')
                            ->icon('heroicon-o-user')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('customer_name')->label('Nama Pelanggan')->weight('semibold'),
                                TextEntry::make('license_plate')->label('Plat Nomor')->placeholder('—'),
                                TextEntry::make('customer_phone')
                                    ->label('No. Telepon / WhatsApp')
                                    ->icon('heroicon-o-phone')
                                    ->copyable()
                                    ->url(fn (Quotation $record) => filled($record->customer_phone) ? 'https://wa.me/' . \App\Support\PhoneFormatter::toWhatsAppNumber($record->customer_phone) : null, true)
                                    ->placeholder('—'),
                                TextEntry::make('customer_email')->label('Email')->icon('heroicon-o-envelope')->copyable()->placeholder('—'),
                                TextEntry::make('message')->label('Catatan / Kebutuhan Pelanggan')->placeholder('—')->columnSpanFull(),
                            ]),

                        InfolistSection::make('Kendaraan')
                            ->icon('heroicon-o-truck')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('vehicle.brand')->label('Merek Kendaraan')->placeholder('—'),
                                TextEntry::make('vehicle.model')->label('Tipe Kendaraan')->placeholder('—'),
                                TextEntry::make('vehicle.variant')->label('Varian')->placeholder('—'),
                            ]),

                        InfolistSection::make('Produk yang Diminati')
                            ->icon('heroicon-o-cube')
                            ->schema([
                                RepeatableEntry::make('items')
                                    ->hiddenLabel()
                                    ->schema([
                                        TextEntry::make('filmProduct.name')->label('Produk Film')->weight('semibold'),
                                        TextEntry::make('quantity')->label('Jumlah')->badge()->color('gray')->formatStateUsing(fn ($state) => '× ' . $state),
                                        TextEntry::make('notes')->label('Keterangan')->placeholder('—'),
                                    ])
                                    ->columns(3),
                            ]),
                    ]),

                InfolistGroup::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        InfolistSection::make('Penanganan')
                            ->icon('heroicon-o-adjustments-horizontal')
                            ->schema([
                                TextEntry::make('quotation_number')->label('No. Quotation')->copyable(),
                                TextEntry::make('status')
                                    ->label('Status Follow-up')
                                    ->badge()
                                    ->formatStateUsing($statusLabel)
                                    ->color(fn (string $state) => match ($state) {
                                        'new'       => 'info',
                                        'contacted' => 'warning',
                                        'closed'    => 'success',
                                        'cancelled' => 'danger',
                                        default     => 'gray',
                                    }),
                                TextEntry::make('outcome')
                                    ->label('Hasil')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state) => $state === 'won' ? 'Deal' : ($state === 'lost' ? 'Tidak Jadi' : 'Belum ada hasil'))
                                    ->color(fn (?string $state) => match ($state) { 'won' => 'success', 'lost' => 'danger', default => 'gray' }),
                                TextEntry::make('owner.name')->label('Penanggung Jawab')->placeholder('Belum ditentukan'),
                                TextEntry::make('store.name')->label('Toko/Dealer')->placeholder('—'),
                            ]),

                        InfolistSection::make('Jejak Lead')
                            ->icon('heroicon-o-clock')
                            ->schema([
                                TextEntry::make('created_at')->label('Masuk')->dateTime('d M Y H:i'),
                                TextEntry::make('contacted_at')
                                    ->label('Direspons Setelah')
                                    ->state(fn (Quotation $record) => $record->contacted_at
                                        ? $record->created_at->diffForHumans($record->contacted_at, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE)
                                        : null)
                                    ->placeholder('Belum direspons'),
                                TextEntry::make('source')
                                    ->label('Sumber')
                                    ->formatStateUsing(fn (string $state) => $state === 'customer' ? 'Pelanggan' : 'Input Staff'),
                                TextEntry::make('channel')
                                    ->label('Kanal')
                                    ->state(fn (Quotation $record) => \App\Support\LeadAttribution::CHANNELS[$record->channel] ?? 'Tidak tercatat'),
                                TextEntry::make('utm_campaign')->label('Kampanye')->placeholder('—'),
                            ]),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $statusColor = fn (?string $state) => match ($state) {
            'new'       => 'info',
            'contacted' => 'warning',
            'closed'    => 'success',
            'cancelled' => 'danger',
            default     => 'gray',
        };

        return $table
            ->columns([
                // Pelanggan sebagai kolom utama: nama tebal, nomor telepon di bawahnya (dua hal yang dicari staf saat follow-up).
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->weight('medium')
                    ->description(fn (Quotation $record) => $record->customer_phone)
                    ->searchable(['customer_name', 'customer_phone', 'customer_email']),

                // Kendaraan dengan produk yang diminati di bawahnya (triase cepat tanpa membuka record; eager-load 'items.filmProduct').
                Tables\Columns\TextColumn::make('vehicle.model')
                    ->label('Kendaraan')
                    ->formatStateUsing(fn ($record) => $record->vehicle
                        ? "{$record->vehicle->brand} {$record->vehicle->model}"
                            . ($record->vehicle->variant ? " — {$record->vehicle->variant}" : '')
                        : '—')
                    ->description(fn (Quotation $record) => $record->items
                        ->map(fn ($item) => $item->filmProduct?->name)
                        ->filter()
                        ->join(', ') ?: null)
                    ->wrap(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->icon(fn (?string $state) => match ($state) {
                        'new'       => 'heroicon-m-bell-alert',
                        'contacted' => 'heroicon-m-chat-bubble-left-ellipsis',
                        'closed'    => 'heroicon-m-check-circle',
                        'cancelled' => 'heroicon-m-x-circle',
                        default     => null,
                    })
                    ->color($statusColor)
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'new' => 'New', 'contacted' => 'Contacted', 'closed' => 'Closed', 'cancelled' => 'Cancelled', default => $state,
                    })
                    // Hasil (Deal / Tidak Jadi) tampil di bawah status, jadi tabel tidak perlu kolom terpisah untuk keduanya.
                    ->description(fn (Quotation $record): ?string => match ($record->outcome) {
                        'won' => 'Deal',
                        'lost' => 'Tidak Jadi',
                        default => null,
                    })
                    ->tooltip(fn (?string $state): ?string => \App\Support\Tooltips::quotationStatus($state)),

                Tables\Columns\TextColumn::make('outcome')
                    ->label('Hasil')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state === 'won' ? 'Deal' : ($state === 'lost' ? 'Tidak Jadi' : '—'))
                    ->color(fn (?string $state) => match ($state) { 'won' => 'success', 'lost' => 'danger', default => 'gray' })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('owner.name')
                    ->label('Penanggung Jawab')
                    ->placeholder('Belum ditentukan')
                    ->color(fn (Quotation $record) => $record->owner ? null : 'gray'),

                // Masuk: tanggal, dengan "berapa lama lalu" di bawahnya.
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Masuk')
                    ->dateTime('d M Y H:i')
                    ->description(fn (Quotation $record) => $record->created_at?->locale('id')->diffForHumans())
                    ->sortable(),

                // SLA follow-up: terisi otomatis begitu status pertama kali berubah dari 'new' (QuotationObserver::updating()).
                Tables\Columns\TextColumn::make('contacted_at')
                    ->label('Direspons Setelah')
                    ->state(fn (Quotation $record) => $record->contacted_at
                        ? $record->created_at->diffForHumans($record->contacted_at, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE)
                        : null)
                    ->placeholder('Belum direspons')
                    ->color(fn (Quotation $record) => ! $record->contacted_at && $record->created_at->lt(now()->subHours(24)) ? 'danger' : null)
                    // Lead baru yang terlambat direspons sudah ditandai garis merah di barisnya, jadi kolom ini cukup tersedia lewat tombol kolom.
                    ->toggleable(isToggledHiddenByDefault: true),

                // Kolom pelengkap: tersedia lewat tombol kolom, disembunyikan bawaan agar tabel tidak padat.
                Tables\Columns\TextColumn::make('quotation_number')
                    ->label('No. Quotation')
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('source')
                    ->label('Sumber')
                    ->badge()
                    ->color(fn (string $state) => $state === 'customer' ? 'gray' : 'info')
                    ->formatStateUsing(fn (string $state) => $state === 'customer' ? 'Pelanggan' : 'Input Staff')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('channel')
                    ->label('Kanal')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => \App\Support\LeadAttribution::CHANNELS[$state] ?? 'Tidak tercatat')
                    ->color(fn (?string $state) => $state ? 'info' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('utm_campaign')
                    ->label('Kampanye')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('message')
                    ->label('Catatan Pelanggan')
                    ->placeholder('—')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Status dipindah ke tab di atas daftar (ListQuotations::getTabs), jadi tidak ada lagi filter Status di sini.
            ->filters([
                Tables\Filters\SelectFilter::make('channel')
                    ->label('Kanal')
                    ->options(\App\Support\LeadAttribution::CHANNELS),
                Tables\Filters\SelectFilter::make('outcome')
                    ->label('Hasil')
                    ->options(\App\Support\LeadOutcome::OUTCOMES),
                Tables\Filters\SelectFilter::make('owner_user_id')
                    ->label('Penanggung Jawab')
                    // Dapat dicari: daftar pilihan memakai kotak Filament (maksimal sekitar 15 rem, terbuka ke bawah), bukan daftar bawaan browser yang
                    // panjang dan terbuka ke atas bila ruang di bawah kurang.
                    ->searchable()
                    ->preload()
                    ->relationship('owner', 'name'),
                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->searchable()
                    ->preload()
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            // Aksi utama (WhatsApp, Lihat) sebagai ikon; sisanya dikelompokkan di menu "...", supaya baris tidak penuh tombol.
            ->actions([
                Tables\Actions\Action::make('whatsapp')
                    ->label('WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->iconButton()
                    ->tooltip('Hubungi lewat WhatsApp')
                    ->visible(fn (Quotation $record) => filled($record->customer_phone))
                    ->url(fn (Quotation $record) => 'https://wa.me/' . \App\Support\PhoneFormatter::toWhatsAppNumber($record->customer_phone))
                    ->openUrlInNewTab(),

                Tables\Actions\ViewAction::make()->iconButton()->tooltip('Lihat detail'),

                Tables\Actions\ActionGroup::make([
                    // Ubah status cepat: aksi paling sering tiap hari. Memicu event 'updating' yang sama, jadi contacted_at tetap
                    // terisi otomatis (QuotationObserver::updating()).
                    Tables\Actions\Action::make('quickStatus')
                        ->label('Ubah Status')
                        ->icon('heroicon-o-arrow-path')
                        ->form([
                            Forms\Components\Select::make('status')
                                ->label('Status Follow-up')
                                ->options([
                                    'new'       => 'New',
                                    'contacted' => 'Contacted',
                                    'closed'    => 'Closed',
                                    'cancelled' => 'Cancelled',
                                ])
                                ->required(),
                        ])
                        ->fillForm(fn (Quotation $record) => ['status' => $record->status])
                        ->action(fn (Quotation $record, array $data) => $record->update(['status' => $data['status']])),

                    Tables\Actions\Action::make('call')
                        ->label('Telepon')
                        ->icon('heroicon-o-phone')
                        ->visible(fn (Quotation $record) => filled($record->customer_phone))
                        ->url(fn (Quotation $record) => 'tel:' . $record->customer_phone),

                    Tables\Actions\EditAction::make(),

                    Tables\Actions\DeleteAction::make()
                        ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
                ])->icon('heroicon-o-ellipsis-horizontal')->tooltip('Aksi lain'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
                ]),
            ])
            // Lead baru yang menunggu lebih dari 24 jam diberi garis merah lembut di kiri baris (gaya di admin-ui-styles).
            ->recordClasses(fn (Quotation $record) => $record->status === 'new' && $record->created_at?->lt(now()->subHours(24)) ? 'cu-row-late' : null)
            ->emptyStateIcon('heroicon-o-inbox')
            ->emptyStateHeading('Belum ada lead')
            ->emptyStateDescription('Permintaan penawaran dari website, aplikasi, dan input staf akan muncul di sini.')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25)
            // 'sort_priority' dihitung di getEloquentQuery(): lead 'new' selalu di atas (yang paling lama menunggu paling atas),
            // sisanya terbaru dulu. Diabaikan otomatis kalau staf mengurutkan lewat kepala kolom.
            ->defaultSort('sort_priority');
    }

    public static function getRelations(): array
    {
        // ItemsRelationManager DIHAPUS — duplikat dengan Repeater "Produk
        // yang Diminati" di form() (baris ~147), sama-sama CRUD ke relasi
        // `items`. RelationManager cuma muncul di halaman Edit (tidak ada
        // di Create), sedangkan Repeater tersedia di keduanya, jadi
        // Repeater yang dipertahankan.
        return [
            \App\Filament\Resources\RelationManagers\ActivityHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListQuotations::route('/'),
            'create' => Pages\CreateQuotation::route('/create'),
            'view'   => Pages\ViewQuotation::route('/{record}'),
            'edit'   => Pages\EditQuotation::route('/{record}/edit'),
        ];
    }

    public static function generateQuotationNumber(): string
    {
        do {
            $candidate = 'QTN-'.now()->format('Ym').'-'.Str::upper(Str::random(4));
        } while (static::getModel()::where('quotation_number', $candidate)->exists());

        return $candidate;
    }
}
