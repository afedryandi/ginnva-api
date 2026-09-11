<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\Refund;
use App\Models\Store;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Penjualan Outlet" — diminta 2026-09-09, analog "Penjualan Outlet"
 * Majoo: rekap pendapatan per cabang/toko dalam 1 rentang tanggal.
 * Data langsung dari Booking.store_id (sudah ada, tidak ada kolom baru).
 *
 * Sumber & logika pendapatan SAMA PERSIS dengan laporan Penjualan lain
 * (whereHas('journalEntry'), transaction_amount > 0, amount_received
 * NULL = lunas penuh) — satu sumber kebenaran.
 *
 * BUG DIPERBAIKI 2026-09-11 (ditemukan saat audit): "Total Penjualan"
 * SEBELUMNYA gross (transaction_amount saja), TIDAK dikurangi refund —
 * beda dari Dashboard/Ringkasan/Per Periode yang konsisten pakai angka
 * BERSIH. Sekarang refund per toko dihitung & dikurangkan, supaya angka
 * di sini SELALU sama persis dengan laporan lain untuk toko & periode
 * yang sama.
 *
 * Staff store-scoped (bukan isFullAccess) TETAP bisa akses halaman ini
 * tapi cuma lihat toko sendiri (1 baris) — sama pola scoping yang
 * dipakai SalesResource/LayananReport, BUKAN dibatasi ke isFullAccess
 * saja, supaya store manager tetap bisa lihat performa tokonya sendiri.
 */
class SalesByOutletReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?string $navigationLabel = 'Penjualan Outlet';

    protected static ?string $title = 'Penjualan Outlet';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.sales-by-outlet-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
        ])->columns(2)->statePath('data');
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;

        // Refund per toko -- SAMA definisi dengan seluruh laporan
        // Penjualan lain: dikelompokkan berdasarkan created_at refund itu
        // sendiri (kapan DIPROSES), bukan tanggal booking-nya.
        $refundByStoreId = Refund::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereHas('booking', function ($q) use ($isFullAccess, $user) {
                if (! $isFullAccess) {
                    $q->where('store_id', $user?->store_id);
                }
            })
            ->with('booking:id,store_id')
            ->get(['amount', 'booking_id', 'created_at'])
            ->groupBy(fn (Refund $r) => $r->booking?->store_id)
            ->map(fn ($group) => (float) $group->sum('amount'));

        $stores = Store::query()
            ->when(! $isFullAccess, fn ($q) => $q->where('id', $user?->store_id))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Store $store) use ($from, $to, $refundByStoreId) {
                $bookings = Booking::query()
                    ->where('store_id', $store->id)
                    ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
                    ->where('transaction_amount', '>', 0)
                    ->get(['transaction_amount', 'amount_received', 'product_kaca_film', 'product_ppf']);

                $grossRevenue = (float) $bookings->sum('transaction_amount');
                $refund = (float) ($refundByStoreId[$store->id] ?? 0);
                $revenue = $grossRevenue - $refund;
                $received = (float) $bookings->sum(fn (Booking $b) => $b->amount_received !== null ? (float) $b->amount_received : (float) $b->transaction_amount);
                // "Produk" -- jumlah kategori produk (Kaca Film/PPF)
                // terpasang, sama pola dengan SalesByPeriodReport/
                // SalesDashboard (BUKAN jumlah SKU spesifik, film_product_id
                // belum wajib diisi).
                $products = $bookings->sum(fn (Booking $b) => ($b->product_kaca_film ? 1 : 0) + ($b->product_ppf ? 1 : 0));

                return [
                    'store' => $store,
                    'count' => $bookings->count(),
                    'grossRevenue' => $grossRevenue,
                    'refund' => $refund,
                    'revenue' => $revenue,
                    'received' => $received,
                    'outstanding' => max(0, $grossRevenue - $received),
                    'avg' => $bookings->count() > 0 ? $revenue / $bookings->count() : 0,
                    'products' => $products,
                    'productsPerTransaction' => $bookings->count() > 0 ? $products / $bookings->count() : 0,
                ];
            })
            ->sortByDesc('revenue')
            ->values();

        $totalRevenue = $stores->sum('revenue');
        $totalRefund = $stores->sum('refund');
        $totalCount = $stores->sum('count');
        $totalProducts = $stores->sum('products');

        // Persentase kontribusi tiap outlet terhadap total -- dihitung
        // di sini (bukan di view) supaya konsisten kalau totalnya 0
        // (hindari division by zero, tampilkan 0% bukan error/NaN).
        $stores = $stores->map(function ($row) use ($totalRevenue, $totalCount, $totalProducts) {
            $row['revenuePct'] = $totalRevenue > 0 ? $row['revenue'] / $totalRevenue * 100 : 0;
            $row['countPct'] = $totalCount > 0 ? $row['count'] / $totalCount * 100 : 0;
            $row['productsPct'] = $totalProducts > 0 ? $row['products'] / $totalProducts * 100 : 0;

            return $row;
        });

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $stores,
            'totalRevenue' => $totalRevenue,
            'totalRefund' => $totalRefund,
            'totalCount' => $totalCount,
            'totalProducts' => $totalProducts,
        ];
    }
}
