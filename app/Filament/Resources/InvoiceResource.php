<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvoiceResource\Pages;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\FilmProduct;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Modul Invoice (2026-09-15, analog "Daftar Invoice" Majoo). V1 murni
 * dokumen tagihan/cetak, TIDAK posting ke Jurnal Umum, TIDAK ada
 * perhitungan pajak/PPN (masih menunggu keputusan bisnis) -- lihat
 * InvoiceService & migrasi create_invoices_table untuk alasan lengkap.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $cluster = \App\Filament\Clusters\BookingCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 200-299 "Dokumen".
    protected static ?string $navigationGroup = 'Order & Lead';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Invoice';

    protected static ?string $modelLabel = 'Invoice';

    protected static ?string $pluralModelLabel = 'Daftar Invoice';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false) && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'create', true) ?? false);
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny()
            && $record->isEditable()
            && (auth()->user()?->hasModuleAction(static::class, 'update', true) ?? false);
    }

    /**
     * hasModuleAction(..., false) (audit Majoo f64, 2026-09-23) —
     * default FALSE (tetap ketat spt sebelumnya, isFullAccess()-only).
     */
    public static function canDelete($record): bool
    {
        $user = auth()->user();

        $allowed = $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));

        return $allowed && $record->status === 'draft';
    }

    public static function getEloquentQuery(): Builder
    {
        // GAP DIPERBAIKI 2026-09-25 (audit Invoice) -- 'items' SEBELUMNYA
        // ikut di-eager-load di sini, padahal tidak satu pun kolom tabel
        // index (invoice_number, customer_name, store.name, total,
        // remaining, status) butuh data item. Halaman yang genuinely
        // butuh (cetak PDF) sudah loadMissing(['items', ...]) sendiri
        // (lihat action 'print' di bawah) -- dihapus dari sini supaya
        // listing tidak menarik data item yang tidak dipakai tiap kali
        // dibuka.
        $query = parent::getEloquentQuery()->with(['store', 'customer', 'booking']);
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Informasi Invoice')
                ->description('Toko, referensi booking, dan tanggal tagihan.')
                ->icon('heroicon-o-document-text')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Forms\Components\Select::make('store_id')
                        ->label('Toko')
                        ->relationship('store', 'name')
                        ->default(fn () => auth()->user()?->store_id)
                        ->disabled(fn () => ! (auth()->user()?->isFullAccess() ?? false))
                        ->dehydrated()
                        ->required(),

                    Forms\Components\Select::make('booking_id')
                        ->label('Referensi Booking (opsional)')
                        // BUG DIPERBAIKI 2026-09-25 (audit Invoice): SEBELUMNYA
                        // field ini TIDAK PERNAH dikunci saat edit (sama pola
                        // bug yang diperbaiki lebih dulu di SPK). Invoice
                        // adalah dokumen tagihan finansial — staff bisa tanpa
                        // sengaja mengganti referensi booking-nya setelah
                        // invoice dibuat (& sudah dikirim/dicetak ke
                        // customer), merusak traceability invoice-ke-booking
                        // tanpa jejak audit eksplisit (booking_id TIDAK
                        // termasuk kolom yang di-log, lihat
                        // getActivitylogOptions()). Kalau referensinya
                        // memang salah, buat invoice baru — bukan edit diam-
                        // diam yang tak tercatat.
                        ->disabledOn('edit')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Booking::where('booking_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%")
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Booking $b) => [$b->id => "{$b->booking_number} — {$b->customer_name}"]))
                        ->getOptionLabelUsing(fn ($value) => Booking::find($value)?->booking_number)
                        // Memilih booking menarik data pelanggan dan baris produknya ke form (lihat InvoiceBookingPrefill). Semuanya hanya
                        // isian awal dan tetap bisa diubah manual; harga yang belum ada di booking dikosongkan untuk diisi.
                        ->afterStateUpdated(fn (Forms\Set $set, $state) => static::pullFromBooking($set, $state))
                        ->live()
                        ->suffixAction(
                            Forms\Components\Actions\Action::make('pullFromBooking')
                                ->label('Tarik ulang dari Booking')
                                ->icon('heroicon-o-arrow-path')
                                ->tooltip('Isi ulang pelanggan dan produk dari booking (menimpa isian saat ini)')
                                ->visible(fn (Forms\Get $get, string $operation) => $operation === 'create' && filled($get('booking_id')))
                                ->requiresConfirmation()
                                ->modalHeading('Tarik ulang dari booking?')
                                ->modalDescription('Data pelanggan dan baris produk akan diganti dengan data dari booking. Perubahan manual Anda pada bagian itu hilang.')
                                ->action(fn (Forms\Set $set, Forms\Get $get) => static::pullFromBooking($set, $get('booking_id'))),
                        )
                        ->helperText('Memilih booking mengisi pelanggan dan produk otomatis, dan semuanya tetap bisa diubah. Kosongkan kalau invoice ini tidak terkait booking ("Tanpa Nomor Referensi").'),

                    Forms\Components\DatePicker::make('issue_date')
                        ->label('Tanggal Dibuat')
                        ->default(now())
                        ->required(),

                    Forms\Components\DatePicker::make('due_date')
                        ->label('Jatuh Tempo')
                        ->minDate(fn (Forms\Get $get) => $get('issue_date')),
                ]),

            Forms\Components\Section::make('Informasi Pelanggan')
                ->description('Terisi dari booking atau akun pelanggan, tetap bisa diubah.')
                ->icon('heroicon-o-user')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Forms\Components\Select::make('customer_id')
                        ->label('Akun Pelanggan (opsional)')
                        ->relationship('customer', 'name')
                        ->searchable()
                        // Daftar langsung tampil saat diklik (50 pertama); ketik untuk mencari pelanggan lain.
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set, $state) {
                            $customer = Customer::find($state);
                            if ($customer) {
                                $set('customer_name', $customer->name);
                                $set('customer_phone', $customer->phone_number);
                                $set('customer_email', $customer->email);
                            }
                        }),

                    Forms\Components\TextInput::make('customer_name')
                        ->label('Nama Pelanggan')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('customer_phone')
                        ->label('No. Telepon')
                        ->maxLength(30),

                    Forms\Components\TextInput::make('customer_email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),

                    Forms\Components\Textarea::make('billing_address')
                        ->label('Alamat Penagihan')
                        ->rows(2),

                    Forms\Components\Textarea::make('shipping_address')
                        ->label('Alamat Pengiriman')
                        ->rows(2),
                ]),

            Forms\Components\Section::make('Detail Produk')
                ->description('Produk atau jasa yang ditagihkan. Harga diisi manual kalau belum ada di booking.')
                ->icon('heroicon-o-shopping-bag')
                ->schema([
                    // BUKAN ->relationship('items') SENGAJA -- item
                    // disimpan lewat InvoiceService (bukan auto-save
                    // bawaan Filament) supaya nomor invoice + rekalkulasi
                    // total selalu lewat satu jalur resmi, lihat
                    // CreateInvoice/EditInvoice::handleRecordCreation()/
                    // handleRecordUpdate().
                    Forms\Components\Repeater::make('items')
                        ->label('')
                        ->addActionLabel('Tambah Produk')
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                        ->columns(['default' => 2, 'md' => 6])
                        ->live()
                        ->schema([
                            Forms\Components\Select::make('film_product_id')
                                ->label('Produk (opsional)')
                                ->options(fn () => FilmProduct::orderBy('name')->pluck('name', 'id'))
                                ->searchable()
                                ->live()
                                ->columnSpan(2)
                                ->afterStateUpdated(function (Forms\Set $set, $state) {
                                    $product = FilmProduct::find($state);
                                    if ($product) {
                                        $set('name', $product->name);
                                        $set('price', $product->base_price);
                                    }
                                }),

                            Forms\Components\TextInput::make('name')
                                ->label('Nama')
                                ->required()
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('quantity')
                                ->label('Jumlah')
                                ->numeric()
                                ->default(1)
                                ->required(),

                            Forms\Components\TextInput::make('unit')
                                ->label('Satuan')
                                ->default('Unit'),

                            Forms\Components\TextInput::make('price')
                                ->label('Harga')
                                ->numeric()
                                ->prefix('Rp')
                                ->required(),

                            Forms\Components\TextInput::make('discount_percent')
                                ->label('Diskon')
                                ->numeric()
                                ->suffix('%')
                                ->default(0),

                            Forms\Components\TextInput::make('note')
                                ->label('Keterangan baris (opsional)')
                                ->maxLength(255)
                                ->columnSpanFull(),
                        ])
                        ->minItems(1)
                        ->required(),
                ]),

            Forms\Components\Section::make('Rincian Tagihan')
                ->description('Diskon, biaya tambahan, dan catatan di bawah total.')
                ->icon('heroicon-o-calculator')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Forms\Components\Select::make('transaction_discount_type')
                        ->label('Jenis Diskon Transaksi')
                        ->options(['rp' => 'Rp', 'percent' => '%'])
                        ->native(false),

                    Forms\Components\TextInput::make('transaction_discount_value')
                        ->label('Nilai Diskon Transaksi')
                        ->numeric()
                        ->default(0),

                    Forms\Components\TextInput::make('shipping_cost')
                        ->label('Biaya Pengiriman')
                        ->numeric()
                        ->prefix('Rp')
                        ->default(0),

                    Forms\Components\TextInput::make('other_cost')
                        ->label('Biaya Lainnya')
                        ->numeric()
                        ->prefix('Rp')
                        ->default(0),

                    Forms\Components\Textarea::make('notes')
                        ->label('Keterangan')
                        ->columnSpanFull()
                        ->rows(2),

                    Forms\Components\Textarea::make('terms_conditions')
                        ->label('Syarat dan Ketentuan')
                        ->columnSpanFull()
                        ->rows(2),
                ]),
        ]);
    }

    /**
     * Isi form Invoice dari sebuah booking (pelanggan, referensi, baris produk). Hanya booking yang boleh dilihat pengguna
     * (BookingResource::getEloquentQuery() membatasi toko). Semua isian tetap bisa diubah manual setelahnya.
     */
    public static function pullFromBooking(Forms\Set $set, mixed $bookingId): void
    {
        if (blank($bookingId)) {
            return;
        }

        $booking = \App\Filament\Resources\BookingResource::getEloquentQuery()->find($bookingId);

        if (! $booking) {
            return;
        }

        foreach (\App\Services\InvoiceBookingPrefill::dataFor($booking) as $key => $value) {
            // Referensi booking dan tanggal tidak ditimpa. Toko ikut hanya untuk akses penuh (staf toko selalu terkunci ke tokonya).
            if (in_array($key, ['booking_id', 'issue_date'], true) || ($key === 'store_id' && ! (auth()->user()?->isFullAccess() ?? false))) {
                continue;
            }

            $set($key, $value);
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Nomor tegas dan bisa disalin, nama pelanggan sebagai keterangan di bawahnya (satu sel).
                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Invoice')
                    ->weight('semibold')
                    ->description(fn (Invoice $r) => $r->customer_name)
                    ->searchable(['invoice_number', 'customer_name', 'customer_phone'])
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('issue_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->description(fn (Invoice $r) => $r->due_date ? 'Jatuh tempo ' . $r->due_date->translatedFormat('d M Y') : null)
                    ->sortable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('IDR', locale: 'id')
                    ->alignEnd()
                    ->sortable(),

                // Sisa tagihan: merah kalau masih ada yang harus dibayar, abu-abu kalau sudah nol atau invoice di-void.
                Tables\Columns\TextColumn::make('remaining')
                    ->label('Sisa')
                    ->alignEnd()
                    ->getStateUsing(fn (Invoice $r) => 'Rp' . number_format($r->remainingAmount(), 0, ',', '.'))
                    ->color(fn (Invoice $r) => in_array($r->status, ['draft', 'unpaid'], true) && $r->remainingAmount() > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray',
                        'unpaid' => 'warning',
                        'paid' => 'success',
                        'void' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => Invoice::STATUS_LABELS[$state] ?? $state),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false),

                Tables\Filters\Filter::make('issue_range')
                    ->label('Tanggal invoice')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('issue_date', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('issue_date', '<=', $d)))
                    ->indicateUsing(function (array $data): array {
                        $out = [];
                        if ($data['from'] ?? null) {
                            $out[] = 'Dari ' . \Illuminate\Support\Carbon::parse($data['from'])->translatedFormat('d M Y');
                        }
                        if ($data['until'] ?? null) {
                            $out[] = 'Sampai ' . \Illuminate\Support\Carbon::parse($data['until'])->translatedFormat('d M Y');
                        }

                        return $out;
                    }),

                Tables\Filters\Filter::make('jatuh_tempo_lewat')
                    ->label('Lewat jatuh tempo')
                    ->query(fn (Builder $query) => $query->whereIn('status', ['draft', 'unpaid'])->whereNotNull('due_date')->whereDate('due_date', '<', today())),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(3)
            // Aksi yang paling sering dipakai (Lihat, Ubah, Catat Pembayaran) berupa ikon; sisanya dikelompokkan di menu "...", supaya baris tidak penuh tombol.
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton()->tooltip('Lihat detail'),
                Tables\Actions\EditAction::make()->iconButton()->tooltip('Ubah'),
                // GAP DIPERBAIKI 2026-09-25 (audit Invoice) -- SEBELUMNYA
                // cuma ada "Tandai Lunas" (langsung set amount_paid =
                // total penuh), tidak ada cara mencatat pembayaran
                // bertahap/DP padahal skema data (amount_paid) sudah
                // mendukungnya. Lihat InvoiceService::recordPayment().
                Tables\Actions\Action::make('record_payment')
                    ->label('Catat Pembayaran')
                    ->icon('heroicon-o-banknotes')
                    ->color('info')
                    ->iconButton()
                    ->tooltip('Catat Pembayaran')
                    ->visible(fn (Invoice $r) => in_array($r->status, ['draft', 'unpaid'], true))
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Nominal Dibayar')
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->prefix('Rp')
                            ->helperText(fn (Invoice $r) => 'Sisa tagihan saat ini: Rp' . number_format($r->remainingAmount(), 0, ',', '.')),
                    ])
                    ->action(function (Invoice $r, array $data) {
                        try {
                            app(InvoiceService::class)->recordPayment($r, (float) $data['amount']);
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Pembayaran dicatat.')->success()->send();
                    }),

                Tables\Actions\ActionGroup::make([
                // BUG DIPERBAIKI 2026-09-25 (audit Invoice): canDelete()
                // di atas (baris ~67) SUDAH punya logika otorisasi lengkap
                // (full-access, atau hasModuleAction 'delete', DAN status
                // harus 'draft') tapi TIDAK PERNAH terpasang jadi tombol —
                // dead code, tidak ada cara hapus invoice draft dari UI
                // sama sekali. DeleteAction otomatis menghormati
                // canDelete() static resource ini (Filament v3 memanggilnya
                // sebagai sumber otorisasi kalau tidak ada Policy class
                // terdaftar untuk model Invoice).
                Tables\Actions\DeleteAction::make(),

                Tables\Actions\Action::make('mark_paid')
                    ->label('Tandai Lunas')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Invoice $r) => in_array($r->status, ['draft', 'unpaid'], true))
                    ->requiresConfirmation()
                    ->modalDescription('Menandai LUNAS langsung mengisi sisa tagihan penuh. Kalau pembayarannya bertahap/DP, pakai "Catat Pembayaran" alih-alih ini.')
                    ->action(function (Invoice $r) {
                        try {
                            app(InvoiceService::class)->markPaid($r);
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Invoice ditandai Lunas.')->success()->send();
                    }),

                Tables\Actions\Action::make('void')
                    ->label('Void')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Invoice $r) => $r->status !== 'void')
                    ->requiresConfirmation()
                    ->action(function (Invoice $r) {
                        try {
                            app(InvoiceService::class)->void($r);
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Invoice di-void.')->success()->send();
                    }),

                Tables\Actions\Action::make('print')
                    ->label('Cetak PDF')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(function (Invoice $r) {
                        $r->loadMissing(['items', 'store']);
                        $pdf = Pdf::loadView('pdf.invoice', ['invoice' => $r])->setPaper('a4', 'portrait');
                        $filename = str_replace('/', '-', $r->invoice_number) . '.pdf';

                        return response()->streamDownload(fn () => print($pdf->output()), $filename);
                    }),
                ]),
            ]);
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
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}
