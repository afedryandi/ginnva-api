<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuotationResource\Pages;
use App\Models\Quotation;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Booking';

    protected static ?int $navigationSort = 10;

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
        $query = parent::getEloquentQuery()->with('items.filmProduct');

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
        $query->addSelect(\Illuminate\Support\Facades\DB::raw("
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

        return $form->schema([

            /* ── INFO UTAMA ─────────────────────────────────────────── */
            Forms\Components\Section::make('Informasi Quotation')
                ->columns(2)
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

                    Forms\Components\Select::make('store_id')
                        ->label('Toko/Dealer')
                        ->relationship('store', 'name')
                        ->searchable()
                        ->preload()
                        ->visible($isSuperAdmin)
                        ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id),
                ]),

            /* ── DATA PELANGGAN ─────────────────────────────────────── */
            Forms\Components\Section::make('Data Pelanggan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('customer_name')
                        ->label('Nama Pelanggan')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('customer_phone')
                        ->label('No. Telepon / WhatsApp')
                        ->tel()
                        // Disamakan dengan validasi API publik
                        // (QuotationController::submit()) yang mewajibkan
                        // keduanya — sebelumnya form admin di sini
                        // membolehkan lead tanpa telepon/email sama sekali,
                        // padahal keduanya dipakai untuk follow-up (WA call
                        // & konfirmasi email).
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('customer_email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('license_plate')
                        ->label('Plat Nomor')
                        ->maxLength(255),

                    Forms\Components\Textarea::make('message')
                        ->label('Catatan / Kebutuhan Pelanggan')
                        ->columnSpanFull(),
                ]),

            /* ── KENDARAAN (cascading brand → tipe) ────────────────── */
            Forms\Components\Section::make('Kendaraan')
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
                            ->get()
                            ->mapWithKeys(fn ($v) => [$v->id => "{$v->model} (Size {$v->size_category})"])
                        )
                        ->disabled(fn (Get $get) => blank($get('vehicle_brand')))
                        ->live()
                        ->required()
                        ->searchable(),
                ]),

            /* ── PRODUK YANG DIMINATI ───────────────────────────────── */
            Forms\Components\Section::make('Produk yang Diminati')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->relationship('items')
                        ->label('')
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
                                ->columnSpan(2),
                        ])
                        ->columns(3)
                        ->addActionLabel('+ Tambah Produk')
                        ->reorderable(false)
                        ->defaultItems(1),
                ]),
        ]);
    }

    /**
     * SEBELUMNYA tidak ada — ViewQuotation fallback ke form disabled
     * bawaan Filament (form Edit lengkap tapi semua field non-interaktif),
     * bukan tampilan read-only yang dirapikan. Infolist khusus ini
     * mengelompokkan info dengan section yang sama dengan form, tapi
     * lebih ringkas & enak dibaca untuk sekadar melihat detail lead.
     * Lihat audit UI/UX Filament Quotation 2026-08-27.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Informasi Quotation')
                ->columns(3)
                ->schema([
                    TextEntry::make('quotation_number')->label('No. Quotation'),
                    TextEntry::make('status')
                        ->label('Status Follow-up')
                        ->badge()
                        ->formatStateUsing(fn (string $state) => match ($state) {
                            'new'       => 'New',
                            'contacted' => 'Contacted',
                            'closed'    => 'Closed',
                            'cancelled' => 'Cancelled',
                            default     => $state,
                        })
                        ->color(fn (string $state) => match ($state) {
                            'new'       => 'info',
                            'contacted' => 'warning',
                            'closed'    => 'success',
                            'cancelled' => 'danger',
                            default     => 'gray',
                        }),
                    TextEntry::make('store.name')->label('Toko/Dealer')->placeholder('—'),
                    TextEntry::make('created_at')->label('Masuk')->dateTime('d M Y H:i'),
                    TextEntry::make('contacted_at')
                        ->label('Direspons Setelah')
                        ->state(fn (Quotation $record) => $record->contacted_at
                            ? $record->created_at->diffForHumans($record->contacted_at, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE)
                            : null)
                        ->placeholder('Belum direspons'),
                    TextEntry::make('source')
                        ->label('Sumber')
                        ->formatStateUsing(fn (string $state) => $state === 'customer' ? 'Customer' : 'Input Staff'),
                ]),

            InfolistSection::make('Data Pelanggan')
                ->columns(2)
                ->schema([
                    TextEntry::make('customer_name')->label('Nama Pelanggan'),
                    TextEntry::make('customer_phone')->label('No. Telepon / WhatsApp')->placeholder('—'),
                    TextEntry::make('customer_email')->label('Email')->placeholder('—'),
                    TextEntry::make('license_plate')->label('Plat Nomor')->placeholder('—'),
                    TextEntry::make('message')->label('Catatan / Kebutuhan Pelanggan')->placeholder('—')->columnSpanFull(),
                ]),

            InfolistSection::make('Kendaraan')
                ->columns(2)
                ->schema([
                    TextEntry::make('vehicle.brand')->label('Merek Kendaraan')->placeholder('—'),
                    TextEntry::make('vehicle.model')->label('Tipe Kendaraan')->placeholder('—'),
                ]),

            InfolistSection::make('Produk yang Diminati')
                ->schema([
                    RepeatableEntry::make('items')
                        ->label('')
                        ->schema([
                            TextEntry::make('filmProduct.name')->label('Produk Film'),
                            TextEntry::make('quantity')->label('Jumlah'),
                            TextEntry::make('notes')->label('Keterangan')->placeholder('—'),
                        ])
                        ->columns(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('quotation_number')
                    ->label('No. Quotation')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable(),

                Tables\Columns\TextColumn::make('vehicle.model')
                    ->label('Kendaraan')
                    ->formatStateUsing(fn ($record) => $record->vehicle
                        ? "{$record->vehicle->brand} {$record->vehicle->model}"
                        : '—'),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->toggleable(),

                // Sebelumnya tidak ada sama sekali — staff harus buka
                // record satu-satu untuk tahu lead ini minat produk apa,
                // padahal itu info penting untuk triase cepat. Lihat audit
                // UI/UX Filament Quotation 2026-08-27.
                Tables\Columns\TextColumn::make('items_summary')
                    ->label('Produk Diminati')
                    ->state(fn (Quotation $record) => $record->items
                        ->map(fn ($item) => $item->filmProduct?->name)
                        ->filter()
                        ->join(', ') ?: '—')
                    ->wrap()
                    ->limit(60)
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'info'    => 'new',
                        'warning' => 'contacted',
                        'success' => 'closed',
                        'danger'  => 'cancelled',
                    ]),

                Tables\Columns\TextColumn::make('source')
                    ->label('Sumber')
                    ->badge()
                    ->color(fn (string $state) => $state === 'customer' ? 'gray' : 'info')
                    ->formatStateUsing(fn (string $state) => $state === 'customer' ? 'Customer' : 'Input Staff')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Masuk')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                // SLA follow-up — isi otomatis begitu status pertama kali
                // berubah dari 'new' (lihat QuotationObserver::updating()),
                // bukan field yang diisi manual.
                Tables\Columns\TextColumn::make('contacted_at')
                    ->label('Direspons Setelah')
                    ->state(fn (Quotation $record) => $record->contacted_at
                        ? $record->created_at->diffForHumans($record->contacted_at, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE)
                        : null)
                    ->placeholder('Belum direspons')
                    ->color(fn (Quotation $record) => ! $record->contacted_at && $record->created_at->lt(now()->subHours(24)) ? 'danger' : null)
                    ->toggleable(),

                // Sebelumnya cuma terlihat setelah buka form — toggleable
                // & disembunyikan default (bukan info yang selalu perlu
                // tampil), tapi bisa dimunculkan kalau staff mau baca
                // sekilas tanpa buka record. Lihat audit UI/UX Filament
                // Quotation 2026-08-27.
                Tables\Columns\TextColumn::make('message')
                    ->label('Catatan Pelanggan')
                    ->placeholder('—')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'new'       => 'New',
                        'contacted' => 'Contacted',
                        'closed'    => 'Closed',
                        'cancelled' => 'Cancelled',
                    ]),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->actions([
                // Sebelumnya staff harus buka form Edit penuh cuma untuk
                // ubah status "New" -> "Contacted" — padahal ini aksi
                // paling sering dilakukan tiap hari. Modal kecil ini
                // memicu event Eloquent 'updating' yang sama (lewat
                // ->update()), jadi contacted_at tetap terisi otomatis
                // sama seperti lewat form Edit biasa (lihat
                // QuotationObserver::updating()). Lihat audit UI/UX
                // Filament Quotation 2026-08-27.
                Tables\Actions\Action::make('quickStatus')
                    ->label('Ubah Status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
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

                // Sebelumnya Filament sama sekali tidak punya cara cepat
                // menghubungi lead — staff yang kerja dari desktop harus
                // copy-paste nomor manual ke WhatsApp Web. Mobile app
                // sudah punya ini di layar detail (lihat
                // app/staff/quotations/[id].tsx). Lihat audit UI/UX
                // Filament Quotation 2026-08-27.
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('whatsapp')
                        ->label('WhatsApp')
                        ->icon('heroicon-o-chat-bubble-left-right')
                        ->color('success')
                        ->visible(fn (Quotation $record) => filled($record->customer_phone))
                        ->url(fn (Quotation $record) => 'https://wa.me/' . \App\Support\PhoneFormatter::toWhatsAppNumber($record->customer_phone))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('call')
                        ->label('Telepon')
                        ->icon('heroicon-o-phone')
                        ->color('info')
                        ->visible(fn (Quotation $record) => filled($record->customer_phone))
                        ->url(fn (Quotation $record) => 'tel:' . $record->customer_phone),
                ])
                    ->label('Hubungi')
                    ->icon('heroicon-o-phone')
                    ->color('gray'),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
                ]),
            ])
            // Sebelumnya defaultSort('created_at', 'desc') murni — lead
            // "New" lama bisa terkubur di bawah lead baru yang statusnya
            // sudah closed, tidak ada dorongan visual untuk prioritaskan
            // yang overdue (walau sudah ada notifikasi harian
            // NotifyStaleQuotations). 'sort_priority' dihitung di
            // getEloquentQuery() — lead 'new' SELALU di atas (paling
            // lama menunggu di paling atas — paling mendesak), baru di
            // bawahnya sisanya diurutkan terbaru dulu. Diabaikan
            // otomatis kalau staff klik header kolom lain untuk sort
            // manual. Lihat audit UI/UX Filament Quotation 2026-08-27
            // (perbaikan susulan — versi Closure sebelumnya ternyata
            // tidak didukung).
            ->defaultSort('sort_priority');
    }

    public static function getRelations(): array
    {
        // ItemsRelationManager DIHAPUS — duplikat dengan Repeater "Produk
        // yang Diminati" di form() (baris ~147), sama-sama CRUD ke relasi
        // `items`. RelationManager cuma muncul di halaman Edit (tidak ada
        // di Create), sedangkan Repeater tersedia di keduanya, jadi
        // Repeater yang dipertahankan.
        return [];
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
