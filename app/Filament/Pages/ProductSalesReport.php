<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\FilmProduct;
use App\Models\Refund;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Penjualan Produk" — diminta 2026-09-09, analog "Penjualan Produk"
 * Majoo. AWALNYA (audit Majoo 2026-09-08) grup "Laporan Produk" secara
 * keseluruhan ditandai tidak relevan (departemen/kategori/ekstra/paket
 * ala katalog retail) -- itu BENAR untuk sub-item lainnya, TAPI keliru
 * untuk "Penjualan Produk" murni: itu breakdown per SKU, dan itu PERSIS
 * yang sudah disiapkan infrastrukturnya lewat Booking.film_product_id
 * (lihat migrasi 2026_09_08_000001, ditambahkan untuk "Produk
 * Terlaris"). Dikoreksi 2026-09-09 setelah user tunjukkan screenshot —
 * bahkan di data Majoo sendiri kolom Departemen/Kategori kosong ("-")
 * untuk produk Ginnva, konfirmasi itu memang bukan intinya.
 *
 * KETERBATASAN PENTING: film_product_id di Booking OPSIONAL dan BARU
 * ditambahkan -- booking lama (dan booking baru yang belum diisi staff)
 * TIDAK punya nilai ini. Baris "Belum Diisi SKU" di laporan ini
 * mengelompokkan booking yang sudah masuk pendapatan tapi belum
 * ditandai produk spesifiknya -- BUKAN dihilangkan dari total, supaya
 * total Penjualan Produk tetap sama dengan total Penjualan sungguhan.
 * Akurasi laporan ini akan membaik seiring staff mulai konsisten isi
 * field "Varian Produk (SKU)" di form Booking.
 *
 * Sumber pendapatan SAMA PERSIS dengan laporan Penjualan lain
 * (whereHas('journalEntry'), transaction_amount > 0).
 */
class ProductSalesReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Produk';

    protected static ?string $navigationLabel = 'Penjualan Produk';

    protected static ?string $title = 'Penjualan Produk';

    // 10 -- diverifikasi 2026-09-09 via source code Filament
    // (HasSubNavigation::getCachedSubNavigation()): urutan GRUP sidebar
    // di dalam Cluster ditentukan oleh navigationSort TERKECIL di antara
    // SEMUA item lintas grup (bukan oleh navigationGroups() array sama
    // sekali) -- item dengan sort terkecil "menang" duluan jadi grup
    // pertama yang muncul. Semua grup laporan di cluster Penjualan
    // SEKARANG pakai sistem BAND berjarak 100 (Penjualan=1-9, Produk=10,
    // Jasa=100-an, Promo=200, Pelanggan=300, Karyawan=400-an,
    // Persediaan=500, Settlement=600) supaya tidak collision lagi
    // selamanya, tidak peduli berapa banyak halaman ditambahkan ke tiap
    // grup ke depannya.
    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.product-sales-report';

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

        $bookings = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->where('transaction_amount', '>', 0)
            ->with('filmProduct:id,sku,name,product_type')
            ->get(['id', 'transaction_amount', 'film_product_id']);

        $totalRevenue = (float) $bookings->sum('transaction_amount');
        $totalCount = $bookings->count();

        // Refund per produk (diminta 2026-09-09, sekarang bisa dihitung
        // berkat fitur Refund) -- di-atribusikan ke film_product_id
        // BOOKING yang di-refund (bukan tanggal booking-nya), rentang
        // filter berdasarkan created_at refund itu sendiri, konsisten
        // dengan RefundReport. Booking yang belum diisi SKU -> masuk
        // bucket "Belum Diisi SKU" juga, sama seperti penjualannya.
        $refunds = Refund::query()
            ->whereBetween('created_at', [$from, $to])
            ->with('booking:id,film_product_id')
            ->get(['amount', 'booking_id']);

        $refundByProductId = $refunds->groupBy(fn (Refund $r) => $r->booking?->film_product_id)
            ->map(fn ($group) => ['count' => $group->count(), 'amount' => (float) $group->sum('amount')]);

        $rows = $bookings->groupBy('film_product_id')
            ->map(function ($group, $filmProductId) use ($refundByProductId) {
                $filmProduct = $group->first()->filmProduct;
                $refundRow = $refundByProductId->get($filmProductId ?: null, ['count' => 0, 'amount' => 0.0]);

                return [
                    'product' => $filmProduct,
                    'sku' => $filmProduct?->sku ?? '—',
                    'name' => $filmProduct?->name ?? 'Belum Diisi SKU',
                    'type' => match ($filmProduct?->product_type) {
                        'window_film' => 'Kaca Film',
                        'ppf' => 'PPF',
                        'color_change' => 'Color Change',
                        default => '—',
                    },
                    'count' => $group->count(),
                    'revenue' => (float) $group->sum('transaction_amount'),
                    'refundCount' => $refundRow['count'],
                    'refundAmount' => $refundRow['amount'],
                ];
            })
            ->sortByDesc(fn ($row) => $row['product'] === null ? -1 : $row['revenue']) // "Belum Diisi SKU" selalu di bawah, biar tidak dikira produk terlaris
            ->values();

        $rows = $rows->map(function ($row) use ($totalRevenue, $totalCount) {
            $row['revenuePct'] = $totalRevenue > 0 ? $row['revenue'] / $totalRevenue * 100 : 0;
            $row['countPct'] = $totalCount > 0 ? $row['count'] / $totalCount * 100 : 0;

            return $row;
        });

        $unassignedCount = $bookings->whereNull('film_product_id')->count();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totalCount' => $totalCount,
            'totalRevenue' => $totalRevenue,
            'totalRefundAmount' => (float) $refunds->sum('amount'),
            'unassignedCount' => $unassignedCount,
            'unassignedPct' => $totalCount > 0 ? $unassignedCount / $totalCount * 100 : 0,
        ];
    }
}
