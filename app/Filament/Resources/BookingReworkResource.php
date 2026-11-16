<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BookingReworkResource\Pages;
use App\Filament\Resources\Concerns\HasKaryawanAccess;
use App\Models\Booking;
use App\Models\BookingRework;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pekerjaan Ulang (Karyawan Fase 4 lanjutan, 2026-11-03): catat booking yang perlu dikerjakan ulang beserta sumber dan alasannya. Dipakai
 * laporan Kompetensi Installer untuk pembinaan; bukan dasar potongan gaji atau komisi.
 */
class BookingReworkResource extends Resource
{
    use HasKaryawanAccess;

    protected static ?string $model = BookingRework::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $cluster = \App\Filament\Clusters\BookingCluster::class;

    // Dipindah dari klaster Karyawan ke Booking (2026-10-30): ini catatan kejadian pada sebuah booking, ditangani staf booking. Laporan
    // Kompetensi Installer tetap membacanya.
    protected static ?string $navigationGroup = 'Setelah Layanan';

    protected static ?string $navigationLabel = 'Pekerjaan Ulang';

    protected static ?string $modelLabel = 'Pekerjaan Ulang';

    protected static ?string $pluralModelLabel = 'Pekerjaan Ulang';

    protected static ?int $navigationSort = 302;

    public static function getEloquentQuery(): Builder
    {
        return static::limitToOwnStore(parent::getEloquentQuery()->with(['booking:id,booking_number,store_id', 'booking.installers:id,name']));
    }

    public static function form(Form $form): Form
    {
        return $form->columns(1)->schema([
            Forms\Components\Section::make('Kejadian')
                ->description('Booking yang perlu dikerjakan ulang dan dari mana temuannya.')
                ->icon('heroicon-o-arrow-path')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Forms\Components\Select::make('booking_id')
                        ->label('Booking')
                        ->searchable()
                        ->required()
                        ->disabled(fn (?BookingRework $record) => $record !== null)
                        ->dehydrated()
                        ->getSearchResultsUsing(function (string $search) {
                            $user = auth()->user();

                            return Booking::query()
                                ->where('booking_number', 'like', "%{$search}%")
                                ->when($user && ! $user->isFullAccess(), fn ($q) => $q->where('store_id', $user->store_id))
                                ->orderByDesc('id')->limit(30)->pluck('booking_number', 'id')->all();
                        })
                        // Daftar langsung tampil saat diklik (30 booking terbaru, dibatasi toko untuk non-akses-penuh); ketik untuk mencari yang lain.
                        ->options(function () {
                            $user = auth()->user();

                            return Booking::query()
                                ->when($user && ! $user->isFullAccess(), fn ($q) => $q->where('store_id', $user->store_id))
                                ->orderByDesc('id')->limit(30)->pluck('booking_number', 'id')->all();
                        })
                        ->getOptionLabelUsing(fn ($value) => Booking::withoutGlobalScopes()->whereKey($value)->value('booking_number'))
                        ->helperText('Cari nomor booking. Installer yang bertugas pada booking itu otomatis ikut terhitung di laporan.'),
                    Forms\Components\Select::make('source')->label('Sumber')->options(BookingRework::SOURCES)->required()->native(false),
                    Forms\Components\DatePicker::make('reported_on')->label('Tanggal dicatat')->required()->default(today())->maxDate(today()),
                    Forms\Components\DatePicker::make('resolved_on')->label('Selesai dikerjakan ulang')->helperText('Isi saat pekerjaan ulang selesai.')->afterOrEqual('reported_on'),
                ]),

            Forms\Components\Section::make('Perbaikan')
                ->description('Apa yang perlu diperbaiki dan catatan pembinaan untuk installer.')
                ->icon('heroicon-o-wrench-screwdriver')
                ->schema([
                    Forms\Components\Textarea::make('reason')->label('Apa yang perlu diperbaiki')->required()->rows(3),
                    Forms\Components\Textarea::make('notes')->label('Catatan pembinaan (opsional)')->rows(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Belum ada pekerjaan ulang')
            ->emptyStateDescription('Catat booking yang perlu dikerjakan ulang supaya terbaca di laporan kompetensi installer.')
            ->emptyStateIcon('heroicon-o-arrow-path')
            ->columns([
                // Nomor booking tegas, installer yang bertugas sebagai keterangan di bawahnya (satu sel).
                Tables\Columns\TextColumn::make('booking.booking_number')
                    ->label('Booking')
                    ->weight('semibold')
                    ->description(fn (BookingRework $record) => $record->booking?->installers->pluck('name')->implode(', ') ?: null)
                    ->searchable(),
                Tables\Columns\TextColumn::make('source')->label('Sumber')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => BookingRework::SOURCES[$state] ?? $state),
                Tables\Columns\TextColumn::make('reason')->label('Perbaikan')->limit(70)->wrap(),
                Tables\Columns\TextColumn::make('reported_on')->label('Dicatat')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('resolved_on')->label('Selesai')->date('d M Y')->placeholder('Belum')
                    ->badge()->color(fn (BookingRework $record) => $record->isOpen() ? 'warning' : 'success'),
            ])
            ->defaultSort('reported_on', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('source')->label('Sumber')->options(BookingRework::SOURCES),
                Tables\Filters\Filter::make('open')->label('Belum selesai')->query(fn (Builder $query) => $query->whereNull('resolved_on')),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->actions([
                Tables\Actions\EditAction::make()->iconButton()->tooltip('Ubah'),
                Tables\Actions\DeleteAction::make()->iconButton()->tooltip('Hapus'),
            ]);
    }
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookingReworks::route('/'),
            'create' => Pages\CreateBookingRework::route('/create'),
            'edit' => Pages\EditBookingRework::route('/{record}/edit'),
        ];
    }
}
