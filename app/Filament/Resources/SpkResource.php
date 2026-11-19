<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SpkResource\Pages;
use App\Models\Booking;
use App\Models\Spk;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Digitalisasi form kertas "Surat Perintah Kerja" (SPK) -- 2026-09-16,
 * V1 murni dokumen isi + cetak PDF (analog InvoiceResource). Checklist
 * (Uraian Pekerjaan/Extra Services/Perlengkapan Kendaraan) SENGAJA
 * bukan ->relationship() bawaan Filament -- disimpan lewat SpkService
 * (lihat CreateSpk/EditSpk) supaya nomor SPK selalu lewat satu jalur
 * resmi, sama pola dengan InvoiceResource "items".
 */
class SpkResource extends Resource
{
    protected static ?string $model = Spk::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $cluster = \App\Filament\Clusters\BookingCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 0-99 "Order & Lead".
    protected static ?string $navigationGroup = 'Order & Lead';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'SPK';

    protected static ?string $modelLabel = 'SPK';

    protected static ?string $pluralModelLabel = 'Surat Perintah Kerja';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false) && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny()
            && (auth()->user()?->canCreateSpk() ?? false)
            && (auth()->user()?->hasModuleAction(static::class, 'create', true) ?? false);
    }

    /**
     * hasModuleAction(..., false) (audit Majoo f64, 2026-09-23) —
     * default FALSE (tetap ketat spt sebelumnya, isFullAccess()-only).
     */
    public static function canEdit($record): bool
    {
        return static::canViewAny()
            && (auth()->user()?->hasModuleAction(static::class, 'update', true) ?? false);
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'delete', false));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['store', 'booking', 'checklistItems', 'damageMarks']);
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    private static function checklistRepeater(string $category, string $label): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make("checklist_{$category}")
            ->label($label)
            ->addActionLabel('Tambah Item')
            ->columns(2)
            ->schema([
                Forms\Components\TextInput::make('label')
                    ->label('Nama Item')
                    ->required()
                    ->columnSpan(1),

                Forms\Components\Toggle::make('is_checked')
                    ->label('Dicentang')
                    ->columnSpan(1),
            ])
            ->default(collect(Spk::DEFAULT_CHECKLIST[$category] ?? [])
                ->map(fn ($item) => ['label' => $item, 'is_checked' => false])
                ->toArray())
            ->reorderable()
            ->collapsible();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('Tabs')
                ->columnSpanFull()
                ->tabs([
                    Forms\Components\Tabs\Tab::make('Informasi')
                        ->schema(static::informationTabSchema()),

                    Forms\Components\Tabs\Tab::make('Checklist')
                        ->schema(static::checklistTabSchema()),

                    Forms\Components\Tabs\Tab::make('Kondisi Kendaraan')
                        ->visible(fn (?Spk $record) => $record !== null)
                        ->badge(fn (?Spk $record) => $record?->damageMarks->count() ?: null)
                        ->schema([
                            Forms\Components\ViewField::make('damage_diagram')
                                ->label('')
                                ->view('filament.spk.damage-diagram')
                                ->viewData(fn (?Spk $record) => ['record' => $record]),

                            static::damageMarksPlaceholder(),
                        ]),

                    Forms\Components\Tabs\Tab::make('Catatan')
                        ->schema([
                            Forms\Components\Textarea::make('notes')
                                ->label('')
                                ->rows(3),
                        ]),
                ]),
        ]);
    }

    /**
     * Dipecah jadi Tabs (Informasi/Checklist/Catatan) supaya halaman
     * tidak terlalu panjang -- sebelumnya semua section ditumpuk
     * vertikal sekaligus (diminta user 2026-09-16).
     */
    /** Tarik data booking ke form SPK (lihat SpkService::prefillFromBooking). Booking dibatasi ke yang boleh dilihat pengguna. */
    public static function pullFromBooking(Forms\Set $set, Forms\Get $get, mixed $bookingId): void
    {
        if (blank($bookingId)) {
            return;
        }

        $booking = Booking::query()
            ->when(! (auth()->user()?->isFullAccess() ?? false), fn (Builder $q) => $q->where('store_id', auth()->user()?->store_id))
            ->find($bookingId);

        if (! $booking) {
            return;
        }

        foreach (\App\Services\SpkService::prefillFromBooking($booking) as $field => $value) {
            $set($field, $value);
        }

        // Layanan yang dipilih customer di booking langsung tercentang di Uraian Pekerjaan (tetap bisa diubah staf).
        $labels = \App\Services\SpkService::workItemLabels($booking);
        $set('checklist_pekerjaan', collect($get('checklist_pekerjaan') ?? [])
            ->map(fn ($row) => ['label' => $row['label'] ?? '', 'is_checked' => in_array($row['label'] ?? '', $labels, true)])
            ->all());
    }

    private static function informationTabSchema(): array
    {
        return [
            Forms\Components\Section::make('Informasi SPK')
                ->description('Nomor, toko, booking asal, dan jam kendaraan masuk dan keluar.')
                ->icon('heroicon-o-clipboard-document-check')
                ->columns(2)
                ->schema([
                    // Nomor SPK: otomatis, atau diketik manual saat membuat (harus unik). Setelah dibuat tidak bisa diubah, karena dipakai dokumen lain (Memo, PDF).
                    Forms\Components\TextInput::make('spk_number')
                        ->label('No. SPK')
                        ->placeholder('Otomatis kalau dikosongkan')
                        ->helperText(fn (string $operation) => $operation === 'create' ? 'Isi kalau ingin memakai nomor sendiri (harus belum dipakai). Kosongkan untuk nomor otomatis.' : null)
                        ->maxLength(100)
                        ->unique(table: 'spks', column: 'spk_number', ignoreRecord: true)
                        ->disabledOn('edit')
                        ->dehydrated(fn (string $operation) => $operation === 'create'),

                    Forms\Components\Select::make('store_id')
                        ->label('Toko')
                        ->relationship('store', 'name')
                        ->default(fn () => auth()->user()?->store_id)
                        ->disabled(fn () => ! (auth()->user()?->isFullAccess() ?? false))
                        ->dehydrated()
                        ->required(),

                    Forms\Components\Select::make('booking_id')
                        ->label('Booking')
                        // BUG DIPERBAIKI 2026-09-25 (audit SPK): SEBELUMNYA
                        // field ini TIDAK PERNAH dikunci saat edit, beda
                        // dari store_id di atas yang sudah benar. Padahal
                        // endpoint mobile (Api\Staff\SpkController::update())
                        // sudah eksplisit melarang booking_id diubah setelah
                        // SPK dibuat ("1 booking = 1 SPK", invarian bisnis).
                        // Tanpa ini, admin bisa memindahkan SPK yang sudah
                        // dicetak/ditandatangani fisik ke booking lain lewat
                        // Filament — merusak jejak dokumen fisik vs digital.
                        // SPK manual (tanpa booking, 2026-11-17) boleh ditautkan ke booking SEKALI belakangan; yang sudah terikat tetap terkunci.
                        ->disabled(fn (string $operation, ?Spk $record) => $operation === 'edit' && filled($record?->booking_id))
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search, ?Spk $record) => Booking::where('status', 'confirmed')
                            ->when(! (auth()->user()?->isFullAccess() ?? false), fn (Builder $q) => $q->where('store_id', auth()->user()?->store_id))
                            ->where(fn (Builder $q) => $q->where('booking_number', 'like', "%{$search}%")
                                ->orWhere('customer_name', 'like', "%{$search}%"))
                            ->where(fn (Builder $q) => $q->doesntHave('spk')->orWhere('id', $record?->booking_id))
                            ->limit(20)
                            ->get()
                            ->mapWithKeys(fn (Booking $b) => [$b->id => "{$b->booking_number} — {$b->customer_name}"]))
                        ->getOptionLabelUsing(fn ($value) => Booking::find($value)?->booking_number)
                        // Memilih booking menarik data pelanggan, kendaraan, dan catatan ke detail SPK, serta mencentang layanan di Uraian
                        // Pekerjaan. Semuanya hanya isian awal dan tetap bisa diubah manual.
                        // Saat menautkan SPK manual yang sudah ada (edit), isian yang sudah diketik tidak ditimpa.
                        ->afterStateUpdated(fn (Forms\Set $set, Forms\Get $get, $state, string $operation) => $operation === 'create' ? static::pullFromBooking($set, $get, $state) : null)
                        ->suffixAction(
                            Forms\Components\Actions\Action::make('pullFromBooking')
                                ->label('Tarik ulang dari Booking')
                                ->icon('heroicon-o-arrow-path')
                                ->tooltip('Isi ulang data pelanggan dan kendaraan dari booking (menimpa isian saat ini)')
                                ->visible(fn (Forms\Get $get, string $operation) => $operation === 'create' && filled($get('booking_id')))
                                ->requiresConfirmation()
                                ->modalHeading('Tarik ulang dari booking?')
                                ->modalDescription('Data pelanggan, kendaraan, dan catatan akan diganti dengan data dari booking. Perubahan manual pada bagian itu hilang.')
                                ->action(fn (Forms\Set $set, Forms\Get $get) => static::pullFromBooking($set, $get, $get('booking_id'))),
                        )
                        ->live()
                        ->helperText('Boleh dikosongkan (SPK manual) dan ditautkan ke booking belakangan. Cuma booking berstatus "Dikonfirmasi" yang belum punya SPK.'),

                    Forms\Components\Select::make('installers')
                        ->label('Installer')
                        ->relationship('installers', 'name', fn (Builder $query, Forms\Get $get) => $query
                            ->where('store_id', $get('store_id') ?: auth()->user()?->store_id)
                            ->whereHas('roles', fn ($q) => $q->where('name', 'installer')))
                        ->multiple()
                        ->preload()
                        ->visible(fn (Forms\Get $get, ?Spk $record) => blank($get('booking_id')) && blank($record?->booking_id))
                        ->helperText('Installer yang mengerjakan SPK manual ini; SPK-nya muncul di akun mereka. SPK berbooking memakai installer booking.'),

                    Forms\Components\DateTimePicker::make('checked_in_at')
                        ->label('Jam Masuk')
                        ->seconds(false)
                        ->default(now()),

                    Forms\Components\DateTimePicker::make('checked_out_at')
                        ->label('Jam Keluar')
                        ->seconds(false)
                        ->minDate(fn (Forms\Get $get) => $get('checked_in_at')),
                ]),

            Forms\Components\Section::make('Data Pelanggan')
                ->description('Terisi dari booking, tetap bisa diubah.')
                ->icon('heroicon-o-user')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('customer_name')
                        ->label('Nama')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone_number')
                        ->label('Telepon')
                        ->maxLength(30),

                    Forms\Components\Textarea::make('address')
                        ->label('Alamat')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Data Kendaraan')
                ->description('Identitas dan kondisi awal kendaraan saat masuk.')
                ->icon('heroicon-o-truck')
                ->columns(['default' => 1, 'sm' => 2, 'xl' => 4])
                ->schema([
                    Forms\Components\TextInput::make('vehicle_plate')
                        ->label('No. Polisi')
                        ->maxLength(20),

                    Forms\Components\TextInput::make('vehicle_vin')
                        ->label('No. Rangka')
                        ->maxLength(50),

                    Forms\Components\TextInput::make('vehicle_brand')
                        ->label('Merek')
                        ->maxLength(100),

                    Forms\Components\TextInput::make('vehicle_year')
                        ->label('Tahun')
                        ->maxLength(4),

                    Forms\Components\Select::make('vehicle_type')
                        ->label('Jenis')
                        ->options(Spk::VEHICLE_TYPE_LABELS)
                        ->native(false),

                    Forms\Components\TextInput::make('vehicle_km')
                        ->label('Kilometer')
                        ->numeric(),

                    Forms\Components\Select::make('fuel_level')
                        ->label('BBM')
                        ->options(Spk::FUEL_LEVEL_LABELS)
                        ->native(false),

                    Forms\Components\TextInput::make('battery_note')
                        ->label('Battery')
                        ->maxLength(100),
                ]),
        ];
    }

    private static function checklistTabSchema(): array
    {
        return [
            Forms\Components\Section::make('Uraian Pekerjaan')
                ->description('Pekerjaan yang dikerjakan; layanan dari booking tercentang otomatis.')
                ->icon('heroicon-o-wrench-screwdriver')
                ->collapsed()
                ->schema([static::checklistRepeater('pekerjaan', '')]),

            Forms\Components\Section::make('Extra Services')
                ->description('Layanan tambahan di luar paket utama.')
                ->icon('heroicon-o-plus-circle')
                ->collapsed()
                ->schema([static::checklistRepeater('extra_service', '')]),

            Forms\Components\Section::make('Perlengkapan Kendaraan')
                ->description('Barang di dalam kendaraan saat diterima.')
                ->icon('heroicon-o-archive-box')
                ->collapsed()
                ->schema([static::checklistRepeater('perlengkapan', '')]),
        ];
    }

    /**
     * Read-only -- titik kerusakan diisi dari mobile app (halaman
     * "Kondisi Kendaraan" tersendiri, lihat SpkController::
     * updateDamageMarks()), Filament belum punya UI diagram interaktif
     * buat menandainya (diminta user 2026-09-16: cukup versi baca-saja
     * dulu di sini, badge + daftar teks sama seperti di PDF).
     */
    private static function damageMarksPlaceholder(): Forms\Components\Placeholder
    {
        return Forms\Components\Placeholder::make('damage_marks_display')
            ->label('')
            ->content(function (?Spk $record) {
                if (! $record || $record->damageMarks->isEmpty()) {
                    return 'Belum ada titik kerusakan ditandai dari aplikasi.';
                }

                return new \Illuminate\Support\HtmlString(
                    '<ul style="margin:0;padding-left:1.1rem;list-style:disc;">' .
                    $record->damageMarks->map(fn ($mark) => sprintf(
                        '<li><strong>%s</strong> — %s (posisi %s%%, %s%%)%s</li>',
                        e($mark->code),
                        e(Spk::DAMAGE_CODE_LABELS[$mark->code] ?? $mark->code),
                        number_format($mark->x_percent, 0),
                        number_format($mark->y_percent, 0),
                        $mark->note ? ' — ' . e($mark->note) : ''
                    ))->implode('') .
                    '</ul>'
                );
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Nomor SPK dan nama pelanggan dalam satu sel (nomor tegas, pelanggan sebagai keterangan) supaya baris mudah dipindai.
                Tables\Columns\TextColumn::make('spk_number')
                    ->label('SPK')
                    ->weight('semibold')
                    ->description(fn (Spk $record) => $record->customer_name)
                    ->searchable(['spk_number', 'customer_name', 'phone_number', 'vehicle_plate'])
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('vehicle_plate')
                    ->label('No. Polisi')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('booking.booking_number')
                    ->label('Booking')
                    ->placeholder('Manual')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('checked_in_at')
                    ->label('Masuk')
                    ->dateTime('d M Y, H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Spk $record) => $record->checked_out_at ? 'Selesai' : 'Dikerjakan')
                    ->color(fn (string $state) => $state === 'Selesai' ? 'success' : 'warning')
                    // Jumlah titik kerusakan tampil di bawah status, jadi tabel tidak perlu kolom Kerusakan sendiri (kolomnya tetap ada lewat tombol kolom).
                    ->description(fn (Spk $record) => $record->damageMarks->count() > 0 ? $record->damageMarks->count() . ' titik kerusakan' : null),

                Tables\Columns\TextColumn::make('damage_marks_count')
                    ->label('Kerusakan')
                    ->state(fn (Spk $record) => $record->damageMarks->count())
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? "{$state} titik" : 'Belum ada')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->searchable()
                    ->preload()
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess() ?? false),

                Tables\Filters\Filter::make('checked_in_range')
                    ->label('Tanggal masuk')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('checked_in_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->whereDate('checked_in_at', '<=', $d)))
                    ->indicateUsing(function (array $data): array {
                        $out = [];
                        if ($data['from'] ?? null) {
                            $out[] = 'Masuk dari ' . \Illuminate\Support\Carbon::parse($data['from'])->translatedFormat('d M Y');
                        }
                        if ($data['until'] ?? null) {
                            $out[] = 'Masuk sampai ' . \Illuminate\Support\Carbon::parse($data['until'])->translatedFormat('d M Y');
                        }

                        return $out;
                    }),

                // Kotak pilih (Semua / Ada / Belum ada), bukan kotak centang: tingginya sama dengan isian lain di baris filter, jadi baris rapi dan
                // sejajar (kotak centang tanpa label mengambang di atas isian bernama).
                Tables\Filters\TernaryFilter::make('ada_kerusakan')
                    ->label('Titik kerusakan')
                    ->placeholder('Semua')
                    ->trueLabel('Ada titik kerusakan')
                    ->falseLabel('Belum ada titik kerusakan')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('damageMarks'),
                        false: fn (Builder $query) => $query->whereDoesntHave('damageMarks'),
                        blank: fn (Builder $query) => $query,
                    ),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(3)
            ->defaultSort('created_at', 'desc')
            // Lihat dan Ubah sebagai ikon; cetak dikelompokkan di menu "...", supaya baris tidak penuh tombol.
            ->actions([
                Tables\Actions\ViewAction::make()->iconButton()->tooltip('Lihat detail'),
                Tables\Actions\EditAction::make()->iconButton()->tooltip('Ubah'),

                Tables\Actions\ActionGroup::make([
                Tables\Actions\Action::make('print')
                    ->label('Cetak PDF')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(function (Spk $record) {
                        $record->loadMissing(['checklistItems', 'damageMarks', 'store', 'booking']);
                        $pdf = Pdf::loadView('pdf.spk', ['spk' => $record, 'isReprint' => false])->setPaper('a4', 'portrait');
                        $filename = str_replace('/', '-', $record->spk_number) . '.pdf';

                        return response()->streamDownload(fn () => print($pdf->output()), $filename);
                    }),

                // Salinan arsip digital SETELAH kendaraan sudah keluar &
                // lembar fisik sudah ditandatangani basah -- tanpa kolom
                // tanda tangan kosong, field "Keluar" tampil apa adanya
                // (bukan dikosongkan seperti lembar asli), lihat
                // pdf.spk::$isReprint (diminta user 2026-09-16).
                Tables\Actions\Action::make('reprint')
                    ->label('Cetak Ulang (Arsip)')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->visible(fn (Spk $record) => $record->checked_out_at !== null)
                    ->action(function (Spk $record) {
                        $record->loadMissing(['checklistItems', 'damageMarks', 'store', 'booking']);
                        $pdf = Pdf::loadView('pdf.spk', ['spk' => $record, 'isReprint' => true])->setPaper('a4', 'portrait');
                        $filename = str_replace('/', '-', $record->spk_number) . '-arsip.pdf';

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
            'index' => Pages\ListSpks::route('/'),
            'create' => Pages\CreateSpk::route('/create'),
            'edit' => Pages\EditSpk::route('/{record}/edit'),
        ];
    }
}
