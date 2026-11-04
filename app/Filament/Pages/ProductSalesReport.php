<?php

namespace App\Filament\Pages;

use App\Exports\ProductSalesReportExport;
use App\Models\Booking;
use App\Models\BookingSaleLine;
use App\Models\FilmProduct;
use App\Models\Refund;
use App\Models\Store;
use App\Models\Technician;
use App\Services\ProductSalesAllocator;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * "Penjualan Produk" -- penjualan per SKU (varian produk) dalam 1 rentang tanggal penjualan diproses.
 *
 * Nominal tiap booking dibagi ke SKU menurut RINCIAN HARGA per layanan (booking_sale_lines) lewat ProductSalesAllocator, sama dengan
 * pembagian pendapatan per jenis di Laporan Jasa. Baris tanpa SKU tampil per jenis layanan ("Belum Diisi SKU -- PPF"); booking lama tanpa
 * rincian memakai SKU utama booking. Satu transaksi dihitung di setiap produknya; total nilai tetap sama dengan penjualan sebenarnya.
 * Refund dibagi dengan proporsi yang sama (menurut hari refund diproses); komisi installer dibagi sebanding nilai tiap SKU.
 *
 * HPP dan Laba Kotor tidak ada di sini (HPP dipegang Accurate, keputusan pemilik 2026-11-04), sejajar Penjualan Per Periode.
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

    // #[Url] (audit 2026-09-11, temuan D) — pola sama laporan Penjualan
    // lain.
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'cabang')]
    public ?int $storeId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasExplicitMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->from = $this->queryDateOrDefault($this->from, now()->startOfMonth());
        $this->to = $this->queryDateOrDefault($this->to, now()->endOfMonth());

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Penjualan Produk 2026-09-29):
        // dikoreksi diam-diam di sini, sama pola dengan laporan Penjualan lain.
        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        // Filter cabang (audit 2026-09-29, sejajar laporan Penjualan lain) -- staff toko TIDAK
        // PERNAH boleh pilih cabang lain, URL yang tidak sah diabaikan.
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeId = null;
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'store_id' => $this->storeId,
        ]);
    }

    private function queryDateOrDefault(mixed $value, Carbon $default): string
    {
        if (! is_string($value) || $value === '') {
            return $default->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return $default->toDateString();
        }
    }

    public function updatedData(mixed $value, string $key): void
    {
        match ($key) {
            'from' => $this->from = $value,
            'to' => $this->to = $value,
            'store_id' => $this->storeId = $value ? (int) $value : null,
            default => null,
        };

        // "Sampai" sebelum "Dari" (audit Penjualan Produk 2026-09-29): sebelumnya diam-diam
        // menghasilkan tabel kosong tanpa penjelasan -- dikoreksi + diberi tahu, sama pola dengan
        // laporan Penjualan lain. TIDAK pakai minDate() reaktif di form (pernah membuat panel
        // filter gagal render di Detail Penjualan) -- validasi murni lewat hook Livewire ini.
        if (in_array($key, ['from', 'to'], true) && $this->from && $this->to && Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
            $this->data['to'] = $this->from;

            Notification::make()
                ->title('Tanggal "Sampai" tidak boleh sebelum "Dari"')
                ->body('Diset sama dengan tanggal "Dari".')
                ->warning()
                ->send();
        }
    }

    public function form(Form $form): Form
    {
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        return $form->schema([
            // Periode Cepat (audit 2026-09-29, sejajar laporan Penjualan lain).
            Select::make('preset')
                ->label('Periode Cepat')
                ->options([
                    'this_month' => 'Bulan ini',
                    'last_month' => 'Bulan lalu',
                    'this_quarter' => 'Kuartal ini',
                    'ytd' => 'Tahun ini (s.d. hari ini)',
                    'last_year' => 'Tahun lalu',
                ])
                ->placeholder('Pilih untuk mengisi tanggal otomatis')
                ->live()
                ->afterStateUpdated(function (?string $state, \Filament\Forms\Set $set) {
                    $range = match ($state) {
                        'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
                        'last_month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
                        'this_quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
                        'ytd' => [now()->startOfYear(), now()],
                        'last_year' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
                        default => null,
                    };

                    if ($range) {
                        $set('from', $range[0]->toDateString());
                        $set('to', $range[1]->toDateString());
                        $this->from = $range[0]->toDateString();
                        $this->to = $range[1]->toDateString();
                    }
                }),

            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),

            // Filter cabang (audit 2026-09-29) -- cuma untuk full-access, sama pola dengan
            // laporan Penjualan lain.
            Select::make('store_id')
                ->label('Toko')
                ->placeholder('Semua toko')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 4 : 3)->statePath('data');
    }

    /**
     * Toko yang BENAR-BENAR berlaku: full-access memilih (null = semua cabang), staf toko dikunci ke tokonya, dan
     * staf tanpa toko dikunci ke -1 (tidak cocok toko mana pun) -- bukan null yang berarti semua cabang.
     * Dipakai getResult() dan log ekspor supaya keduanya merujuk toko yang sama.
     */
    private function effectiveStoreId(): ?int
    {
        $user = auth()->user();

        if ($user?->isFullAccess() ?? false) {
            $chosen = $this->data['store_id'] ?? null;

            return $chosen ? (int) $chosen : null;
        }

        return $user?->store_id ?? -1;
    }

    /** Log ekspor (audit Penjualan Produk 2026-09-29), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'product_sales', 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'store_id' => $this->effectiveStoreId()])
                ->log('Export Penjualan Produk (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-11, temuan B) — pola sama laporan
     * Penjualan lain.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $this->logExport('xlsx');

                    return Excel::download(
                        new ProductSalesReportExport($this->getResult()),
                        'penjualan-produk-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.product_sales_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'penjualan-produk-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        // startOfDay(): nilai DatePicker bisa membawa jam; tanpa ini refund sebelum jam itu di hari pertama tidak terhitung.
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $storeId = $this->effectiveStoreId();

        $bookings = Booking::query()
            ->soldBetween($from->toDateString(), $to->toDateString())
            ->where('transaction_amount', '>', 0)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with(['installers:id', 'saleLines'])
            ->withCount('filmProducts')
            ->get(['id', 'transaction_amount', 'film_product_id', 'product_ppf', 'product_kaca_film', 'product_detailing', 'product_premium_wash']);

        $technicianByUserId = Technician::query()->whereNotNull('user_id')->with('serviceRates')->get()->keyBy('user_id');

        // Tiap booking dibagi ke SKU menurut rincian harganya (ProductSalesAllocator). Satu transaksi dihitung di SETIAP produknya.
        $buckets = [];
        $unassignedBookings = 0;
        $legacyMultiCount = 0;
        $legacyMultiRevenue = 0.0;

        foreach ($bookings as $booking) {
            $amount = (float) $booking->transaction_amount;
            $shares = ProductSalesAllocator::allocate($booking, $amount);
            $hasUnassigned = false;

            foreach ($shares as $key => $share) {
                $buckets[$key] ??= [
                    'product_id' => $share['product_id'], 'service' => $share['service'], 'count' => 0, 'revenue' => 0.0,
                    'refundCount' => 0, 'refundAmount' => 0.0, 'commission' => 0.0, 'hasUnratedJob' => false,
                ];
                $buckets[$key]['count']++;
                $buckets[$key]['revenue'] += $share['amount'];

                // Komisi installer booking dibagi sebanding dengan bagian nilai tiap SKU.
                $ratio = $amount > 0 ? $share['amount'] / $amount : 0.0;
                foreach ($booking->installers as $installer) {
                    $commission = $technicianByUserId->get($installer->id)?->commissionForBooking($booking);
                    if ($commission !== null) {
                        $buckets[$key]['commission'] += $commission * $ratio;
                    } else {
                        $buckets[$key]['hasUnratedJob'] = true;
                    }
                }

                if ($share['product_id'] === null) {
                    $hasUnassigned = true;
                }
            }

            if ($hasUnassigned) {
                $unassignedBookings++;
            }

            // Produk tambahan lama (booking_film_products) tidak punya harga: hanya booking TANPA rincian yang masih terdampak.
            $isLegacy = (bool) (array_values($shares)[0]['legacy'] ?? false);
            if ($isLegacy && $booking->film_products_count > 0) {
                $legacyMultiCount++;
                $legacyMultiRevenue += $amount;
            }
        }

        // Refund dibagi dengan proporsi yang sama dengan penjualan booking-nya; rentang menurut hari refund diproses.
        $refunds = Refund::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($storeId, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $storeId)))
            ->with(['booking' => fn ($q) => $q->select('id', 'transaction_amount', 'film_product_id', 'product_ppf', 'product_kaca_film', 'product_detailing', 'product_premium_wash')->with('saleLines')])
            ->get(['amount', 'booking_id']);

        foreach ($refunds as $refund) {
            if (! $refund->booking) {
                continue;
            }

            foreach (ProductSalesAllocator::allocate($refund->booking, (float) $refund->amount) as $key => $share) {
                $buckets[$key] ??= [
                    'product_id' => $share['product_id'], 'service' => $share['service'], 'count' => 0, 'revenue' => 0.0,
                    'refundCount' => 0, 'refundAmount' => 0.0, 'commission' => 0.0, 'hasUnratedJob' => false,
                ];
                $buckets[$key]['refundCount']++;
                $buckets[$key]['refundAmount'] += $share['amount'];
            }
        }

        $products = FilmProduct::withTrashed()->whereIn('id', collect($buckets)->pluck('product_id')->filter()->unique()->all())->get(['id', 'sku', 'name', 'product_type'])->keyBy('id');
        $serviceLabels = BookingSaleLine::SERVICE_TYPES;

        $totalRevenue = (float) array_sum(array_column($buckets, 'revenue'));
        $slotCount = (int) array_sum(array_column($buckets, 'count'));
        $totalCount = $bookings->count();

        $rows = collect($buckets)
            ->map(function (array $b, string $key) use ($products, $serviceLabels, $totalRevenue, $slotCount) {
                $product = $b['product_id'] ? $products->get($b['product_id']) : null;
                $serviceText = collect(explode('+', $b['service']))->filter()->map(fn ($s) => $serviceLabels[$s] ?? $s)->implode(' + ');

                return [
                    'key' => $key,
                    'product' => $product,
                    'sku' => $product?->sku ?? '—',
                    'name' => $product?->name ?? ($b['product_id'] ? 'Produk Dihapus' : 'Belum Diisi SKU' . ($serviceText !== '' ? ' — ' . $serviceText : '')),
                    'type' => match ($product?->product_type) {
                        'window_film' => 'Kaca Film',
                        'ppf' => 'PPF',
                        'detailing' => 'Detailing',
                        'premium_wash' => 'Premium Wash',
                        'color_change' => 'Ganti Warna',
                        default => $serviceText !== '' ? $serviceText : '—',
                    },
                    'unassigned' => $b['product_id'] === null,
                    'count' => $b['count'],
                    'countPct' => $slotCount > 0 ? $b['count'] / $slotCount * 100 : 0,
                    'revenue' => round($b['revenue'], 2),
                    'revenuePct' => $totalRevenue > 0 ? $b['revenue'] / $totalRevenue * 100 : 0,
                    'refundCount' => $b['refundCount'],
                    'refundAmount' => round($b['refundAmount'], 2),
                    'commission' => round($b['commission'], 2),
                    'hasUnratedJob' => $b['hasUnratedJob'],
                ];
            })
            // "Belum Diisi SKU" selalu di bawah, biar tidak dikira produk terlaris.
            ->sortBy([['unassigned', 'asc'], ['revenue', 'desc']])
            ->values();

        $totalRefundAmount = (float) $refunds->sum('amount');

        return [
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
            'rows' => $rows,
            'totalCount' => $totalCount,
            // Jumlah baris produk (satu transaksi berproduk ganda terhitung di tiap produknya), penyebut "Jumlah %".
            'slotCount' => $slotCount,
            // Headline bersih (setelah refund); baris per produk tetap kotor, kolom Refund tersendiri.
            'totalRevenue' => $totalRevenue - $totalRefundAmount,
            'grossRevenue' => $totalRevenue,
            'totalRefundAmount' => $totalRefundAmount,
            'unassignedCount' => $unassignedBookings,
            'unassignedPct' => $totalCount > 0 ? $unassignedBookings / $totalCount * 100 : 0,
            'unassignedRevenue' => (float) $rows->where('unassigned', true)->sum('revenue'),
            // Booking lama tanpa rincian harga yang punya produk tambahan: seluruh nilainya masih ke produk utama.
            'multiProductCount' => $legacyMultiCount,
            'multiProductRevenue' => $legacyMultiRevenue,
            'totalCommission' => (float) $rows->sum('commission'),
        ];
    }
}
