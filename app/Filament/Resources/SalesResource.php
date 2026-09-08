<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SalesResource\Pages;
use App\Models\Booking;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Penjualan" — diminta 2026-09-08 setelah eksplorasi Dashboard Penjualan
 * Majoo. BUKAN pengganti nama "Booking" (dibahas & disepakati TIDAK
 * diganti — Booking mencakup seluruh siklus lead→jadwal→kerjakan→garansi,
 * jauh lebih luas dari "Penjualan" murni ala Majoo yang cuma sisi
 * transaksi/revenue). Resource ini murni LAPORAN read-only: booking yang
 * SUDAH benar-benar tercatat sebagai pendapatan (ada journalEntry, lihat
 * BookingPostingService) — sub-set dari BookingResource, model & data
 * SAMA PERSIS, tidak ada tabel/kolom baru. Tidak ada create/edit/delete
 * sama sekali (tidak ada Policy terdaftar, Gate default-deny DISENGAJA
 * di sini — sama pola dengan ActivityResource yang murni read-only),
 * koreksi nominal transaksi TETAP lewat "Proses Referral" di
 * BookingResource, bukan dari sini.
 *
 * SEBELUMNYA jadi sub-kategori sidebar di dalam cluster Booking (supaya
 * tidak menambah lebar top-nav) — diminta 2026-09-08 (permintaan
 * susulan) untuk jadi TAB TOP-NAV SENDIRI, dipindah ke PenjualanCluster.
 */
class SalesResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Penjualan';

    protected static ?string $modelLabel = 'Penjualan';

    protected static ?string $pluralModelLabel = 'Penjualan';

    // Item sidebar "Penjualan" ini disembunyikan dulu (diminta 2026-09-08
    // — dianggap redundan dengan nama tab-nya sendiri, breadcrumb
    // "Penjualan > Penjualan"), TAPI resource & route-nya TETAP UTUH
    // (SalesDashboard.php masih boleh dipakai, dan halaman ini masih bisa
    // diakses lewat URL langsung kalau perlu). Hapus override ini untuk
    // memunculkan lagi di sidebar.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function getEloquentQuery(): Builder
    {
        // journalEntry WAJIB ada (bukan cuma transaction_amount > 0) —
        // itu satu-satunya sumber kebenaran "sudah benar-benar tercatat
        // sebagai pendapatan" (lihat BookingPostingService), sama filter
        // yang dipakai BookingRevenueStatsWidget/BookingRevenueByCategoryChart
        // supaya angka di sini SELALU konsisten dengan widget dashboard &
        // Jurnal Umum di Keuangan.
        $query = parent::getEloquentQuery()
            ->with(['store', 'journalEntry'])
            ->whereHas('journalEntry');

        $user = auth()->user();
        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking_number')
                    ->label('No. Booking')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('product')
                    ->label('Produk')
                    ->state(fn (Booking $record) => match (true) {
                        $record->product_kaca_film && $record->product_ppf => 'Kaca Film + PPF',
                        $record->product_ppf => 'PPF',
                        $record->product_kaca_film => 'Kaca Film',
                        default => '—',
                    })
                    ->badge()
                    ->color(fn (Booking $record) => $record->product_kaca_film && $record->product_ppf ? 'gray' : ($record->product_ppf ? 'danger' : 'info')),

                Tables\Columns\TextColumn::make('transaction_amount')
                    ->label('Nilai Transaksi')
                    ->money('IDR', locale: 'id')
                    ->weight('bold')
                    ->sortable(),

                // amount_received NULL = dianggap lunas penuh (lihat
                // BookingPostingService), jadi placeholder-nya HARUS
                // menampilkan nilai transaction_amount, bukan '—'
                // polos — supaya tidak terbaca seolah belum ada
                // pembayaran sama sekali.
                Tables\Columns\TextColumn::make('amount_received')
                    ->label('Diterima')
                    ->formatStateUsing(fn (?string $state, Booking $record) => 'Rp' . number_format((float) ($state ?? $record->transaction_amount), 0, ',', '.'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('outstanding')
                    ->label('Piutang')
                    ->state(function (Booking $record) {
                        $received = $record->amount_received ?? $record->transaction_amount;

                        return max(0, (float) $record->transaction_amount - (float) $received);
                    })
                    ->formatStateUsing(fn (float $state) => $state > 0 ? 'Rp' . number_format($state, 0, ',', '.') : '—')
                    ->color(fn (float $state) => $state > 0 ? 'danger' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('journalEntry.entry_date')
                    ->label('Tanggal Tercatat')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('journalEntry.entry_number')
                    ->label('No. Jurnal')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('entry_date')
                    ->label('Rentang Tanggal Tercatat')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereHas('journalEntry', fn ($q2) => $q2->whereDate('entry_date', '>=', $date)))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereHas('journalEntry', fn ($q2) => $q2->whereDate('entry_date', '<=', $date)))
                    ),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->actions([
                // Koreksi/tandai lunas TETAP lewat "Proses Referral" di
                // BookingResource — di sini murni link lihat, tidak ada
                // EditAction/DeleteAction sama sekali.
                Tables\Actions\Action::make('viewBooking')
                    ->label('Lihat Booking')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('booking_number', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSales::route('/'),
        ];
    }
}
