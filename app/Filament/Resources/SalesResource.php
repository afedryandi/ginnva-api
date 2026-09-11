<?php

namespace App\Filament\Resources;

use App\Exports\SalesExport;
use App\Filament\Resources\SalesResource\Pages;
use App\Models\Booking;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

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
 *
 * SEMPAT disembunyikan dari sidebar (dianggap redundan dengan nama tab),
 * dimunculkan lagi 2026-09-09 sebagai "Detail Penjualan" di dalam grup
 * 'Laporan Penjualan' (lihat catatan navigationGroup di bawah) — struktur
 * grup-per-kategori final, bukan 1 grup 'Laporan' gabungan lagi.
 */
class SalesResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    // Grup sendiri (diminta 2026-09-09) -- sejajar dengan grup kategori
    // laporan lain (Laporan Jasa, Laporan Karyawan, dst), BUKAN nested
    // di dalam grup lain (Filament v3 tidak dukung dropdown bersarang).
    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Detail Penjualan';

    protected static ?string $modelLabel = 'Penjualan';

    protected static ?string $pluralModelLabel = 'Penjualan';

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
            // Stat card "Total Invoice/Lunas/Belum Lunas/Void/Total
            // Diterima" (audit 2026-09-11, temuan #3) — SEBELUMNYA header
            // widget terpisah yang SELALU tampilkan total keseluruhan
            // data, tidak peduli filter apa pun yang aktif. Dipindah ke
            // sini karena Table::header() dievaluasi ULANG tiap kali
            // tabel re-render (ganti filter/pencarian/halaman) DAN
            // menerima $livewire — jadi $livewire->getFilteredTableQuery()
            // di sini SELALU query yang sedang aktif di layar, query yang
            // SAMA dipakai tombol "Export ke Excel" di bawah. Agregasi
            // SQL-nya satu implementasi, lihat SalesDetailStatsWidget::aggregate().
            ->header(fn ($livewire) => view('filament.resources.sales-resource.partials.filtered-stats', [
                'stats' => \App\Filament\Widgets\SalesDetailStatsWidget::aggregate($livewire->getFilteredTableQuery()),
            ]))
            ->columns([
                // No. Invoice diturunkan dari booking_number (sama dengan
                // yang dipakai di PDF "Cetak Invoice") — tidak ada tabel/
                // nomor invoice terpisah. Analog "Daftar Invoice" Majoo.
                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('No. Invoice')
                    ->state(fn (Booking $record) => 'INV/' . $record->booking_number)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('booking_number', 'like', "%{$search}%"))
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('booking_number')
                    ->label('No. Booking')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

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

                // Potongan Promo (audit 2026-09-11, temuan #4) —
                // transaction_amount SUDAH net (dipotong promo), kolom
                // ini cuma menjelaskan berapa potongannya, supaya angka
                // di sini tidak kelihatan "tidak sinkron" dibanding PDF
                // Cetak Invoice yang sudah lebih dulu tampilkan baris
                // ini (lihat BookingResource::downloadInvoicePdf()).
                Tables\Columns\TextColumn::make('spend_promo_discount')
                    ->label('Potongan Promo')
                    ->formatStateUsing(fn (?string $state) => $state && (float) $state > 0 ? 'Rp' . number_format((float) $state, 0, ',', '.') : '—')
                    ->toggleable(isToggledHiddenByDefault: true),

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
                    ->label('Sisa Tagihan')
                    ->state(function (Booking $record) {
                        $received = $record->amount_received ?? $record->transaction_amount;

                        return max(0, (float) $record->transaction_amount - (float) $received);
                    })
                    ->formatStateUsing(fn (float $state) => $state > 0 ? 'Rp' . number_format($state, 0, ',', '.') : '—')
                    ->color(fn (float $state) => $state > 0 ? 'danger' : 'gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Status')
                    ->badge()
                    ->state(function (Booking $record): string {
                        if ($record->status === 'cancelled') {
                            return 'Void';
                        }
                        $received = $record->amount_received ?? $record->transaction_amount;

                        return ((float) $record->transaction_amount - (float) $received) > 0.009 ? 'Belum Lunas' : 'Lunas';
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Lunas' => 'success',
                        'Belum Lunas' => 'warning',
                        default => 'danger',
                    }),

                // Analog "Waktu Order" vs "Waktu Bayar" Majoo (diminta
                // 2026-09-09) -- created_at = booking DIAJUKAN, entry_date
                // = booking BENAR-BENAR tercatat sebagai pendapatan
                // (dibayar/diproses "Referral"). 2 tanggal yang beda arti,
                // bukan duplikat kolom.
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu Order')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('journalEntry.entry_date')
                    ->label('Waktu Bayar')
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

                Tables\Filters\SelectFilter::make('payment_status')
                    ->label('Status Pembayaran')
                    ->options([
                        'lunas' => 'Lunas',
                        'belum_lunas' => 'Belum Lunas',
                        'void' => 'Void',
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'void' => $query->where('status', 'cancelled'),
                        'belum_lunas' => $query->where('status', '!=', 'cancelled')
                            ->whereNotNull('amount_received')
                            ->whereColumn('amount_received', '<', 'transaction_amount'),
                        'lunas' => $query->where('status', '!=', 'cancelled')
                            ->where(fn ($q) => $q->whereNull('amount_received')->orWhereColumn('amount_received', '>=', 'transaction_amount')),
                        default => $query,
                    }),
            ])
            ->headerActions([
                // "Ekspor Laporan" (diminta 2026-09-09, analog tombol di
                // halaman Penjualan Majoo) -- pakai getFilteredTableQuery()
                // (BUKAN query polos Booking::query()) supaya hasil export
                // SELALU ikut filter yang sedang aktif di layar (Rentang
                // Tanggal Tercatat, Toko) -- sama pola yang sudah dipakai &
                // diperbaiki di WarrantyResource (lihat catatan di sana),
                // jangan ulangi bug lama "filter di layar tidak ikut ke
                // export".
                Tables\Actions\Action::make('exportExcel')
                    ->label('Export ke Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn ($livewire) => Excel::download(
                        new SalesExport($livewire->getFilteredTableQuery()),
                        'penjualan-' . now()->format('Ymd-His') . '.xlsx'
                    )),
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

                // "Cetak Invoice" (audit 2026-09-11, temuan #5) — SEBELUMNYA
                // cuma ada di BookingResource, user harus "Lihat Booking"
                // dulu cuma untuk cetak, padahal kolom pertama tabel ini
                // sudah bernama "No. Invoice" (menjanjikan ini Daftar
                // Invoice). Pakai ULANG BookingResource::downloadInvoicePdf()
                // (sekarang public) — satu implementasi PDF, dua pemicu.
                // Syarat visible SAMA PERSIS dengan yang di BookingResource.
                Tables\Actions\Action::make('printInvoice')
                    ->label('Cetak Invoice')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->visible(fn (Booking $record) => $record->status === 'completed' && $record->transaction_amount !== null && (float) $record->transaction_amount > 0)
                    ->action(fn (Booking $record) => BookingResource::downloadInvoicePdf($record)),
            ])
            // 'booking_number' DULU dipakai untuk default sort — TERNYATA
            // salah (audit 2026-09-11): formatnya BKG-YYYYMM-XXXX, 4
            // karakter terakhir RANDOM (lihat Booking::generateBookingNumber()),
            // bukan sequential. "desc" cuma mengurutkan string acak dalam
            // bulan yang sama, BUKAN "terbaru dulu" seperti yang diharapkan
            // dari sebuah Daftar Invoice. Ganti ke tanggal jurnal (kapan
            // BENAR-BENAR tercatat jadi pendapatan) — kolomnya sudah
            // ->sortable() di atas lewat relasi journalEntry.
            ->defaultSort('journalEntry.entry_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSales::route('/'),
        ];
    }
}
