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

    protected static ?int $navigationSort = 25;

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
        $query = parent::getEloquentQuery()->with(['store', 'customer', 'booking', 'items']);
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
                ->columns(2)
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
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Booking::where('booking_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%")
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Booking $b) => [$b->id => "{$b->booking_number} — {$b->customer_name}"]))
                        ->getOptionLabelUsing(fn ($value) => Booking::find($value)?->booking_number)
                        ->afterStateUpdated(function (Forms\Set $set, $state) {
                            $booking = Booking::find($state);
                            if ($booking) {
                                $set('customer_id', $booking->customer_id);
                                $set('customer_name', $booking->customer_name ?? $booking->customer?->name);
                                $set('customer_phone', $booking->phone_number ?? $booking->customer?->phone_number);
                            }
                        })
                        ->live()
                        ->helperText('Kosongkan kalau invoice ini tidak terkait booking manapun ("Tanpa Nomor Referensi").'),

                    Forms\Components\DatePicker::make('issue_date')
                        ->label('Tanggal Dibuat')
                        ->default(now())
                        ->required(),

                    Forms\Components\DatePicker::make('due_date')
                        ->label('Jatuh Tempo')
                        ->minDate(fn (Forms\Get $get) => $get('issue_date')),
                ]),

            Forms\Components\Section::make('Informasi Pelanggan')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('customer_id')
                        ->label('Akun Customer (opsional)')
                        ->relationship('customer', 'name')
                        ->searchable()
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
                        ->columns(6)
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
                                ->label('Harga (Rp)')
                                ->numeric()
                                ->required(),

                            Forms\Components\TextInput::make('discount_percent')
                                ->label('Diskon (%)')
                                ->numeric()
                                ->default(0),
                        ])
                        ->minItems(1)
                        ->required(),
                ]),

            Forms\Components\Section::make('Rincian Tagihan')
                ->columns(2)
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
                        ->default(0),

                    Forms\Components\TextInput::make('other_cost')
                        ->label('Biaya Lainnya')
                        ->numeric()
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('No. Invoice')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('issue_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total Tagihan')
                    ->money('IDR', locale: 'id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('remaining')
                    ->label('Sisa Tagihan')
                    ->getStateUsing(fn (Invoice $r) => 'Rp' . number_format($r->remainingAmount(), 0, ',', '.')),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'gray' => 'draft',
                        'warning' => 'unpaid',
                        'success' => 'paid',
                        'danger' => 'void',
                    ])
                    ->formatStateUsing(fn (string $state) => Invoice::STATUS_LABELS[$state] ?? $state),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(Invoice::STATUS_LABELS),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('mark_paid')
                    ->label('Tandai Lunas')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Invoice $r) => in_array($r->status, ['draft', 'unpaid'], true))
                    ->requiresConfirmation()
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
            ]);
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
