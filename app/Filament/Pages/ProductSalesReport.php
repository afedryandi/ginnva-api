<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\FilmProduct;
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

    protected static ?int $navigationSort = 1;

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

        $rows = $bookings->groupBy('film_product_id')
            ->map(function ($group) {
                $filmProduct = $group->first()->filmProduct;

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
                ];
            })
            ->sortByDesc(fn ($row) => $row['product'] === null ? -1 : $row['revenue']) // "Belum Diisi SKU" selalu di bawah, biar tidak dikira produk terlaris
            ->values();

        $rows = $rows->map(function ($row) use ($totalRevenue) {
            $row['revenuePct'] = $totalRevenue > 0 ? $row['revenue'] / $totalRevenue * 100 : 0;

            return $row;
        });

        $unassignedCount = $bookings->whereNull('film_product_id')->count();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totalCount' => $bookings->count(),
            'totalRevenue' => $totalRevenue,
            'unassignedCount' => $unassignedCount,
            'unassignedPct' => $bookings->count() > 0 ? $unassignedCount / $bookings->count() * 100 : 0,
        ];
    }
}
