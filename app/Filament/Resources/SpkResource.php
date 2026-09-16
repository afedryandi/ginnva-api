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

    protected static ?int $navigationSort = 22;

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
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
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
                        ->schema([static::damageMarksPlaceholder()]),

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
    private static function informationTabSchema(): array
    {
        return [
            Forms\Components\Section::make('Informasi SPK')
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
                        ->label('Booking')
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
                        ->afterStateUpdated(function (Forms\Set $set, $state) {
                            $booking = Booking::find($state);
                            if ($booking) {
                                $set('customer_name', $booking->customer_name ?? $booking->customer?->name);
                                $set('phone_number', $booking->phone_number ?? $booking->customer?->phone_number);
                            }
                        })
                        ->live()
                        ->required()
                        ->helperText('Cuma booking berstatus "Dikonfirmasi" yang belum punya SPK.'),

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
                ->columns(2)
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
                ->collapsed()
                ->schema([static::checklistRepeater('pekerjaan', '')]),

            Forms\Components\Section::make('Extra Services')
                ->collapsed()
                ->schema([static::checklistRepeater('extra_service', '')]),

            Forms\Components\Section::make('Perlengkapan Kendaraan')
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
                Tables\Columns\TextColumn::make('spk_number')
                    ->label('No. SPK')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable(),

                Tables\Columns\TextColumn::make('vehicle_plate')
                    ->label('No. Polisi')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('booking.booking_number')
                    ->label('Booking')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('checked_in_at')
                    ->label('Jam Masuk')
                    ->dateTime('d M Y, H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('damage_marks_count')
                    ->label('Kerusakan')
                    ->state(fn (Spk $record) => $record->damageMarks->count())
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? "{$state} titik" : 'Belum ada'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('print')
                    ->label('Cetak PDF')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->action(function (Spk $record) {
                        $record->loadMissing(['checklistItems', 'damageMarks', 'store', 'booking']);
                        $pdf = Pdf::loadView('pdf.spk', ['spk' => $record])->setPaper('a4', 'portrait');
                        $filename = str_replace('/', '-', $record->spk_number) . '.pdf';

                        return response()->streamDownload(fn () => print($pdf->output()), $filename);
                    }),
            ]);
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
