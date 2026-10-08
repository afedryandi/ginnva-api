<?php

namespace App\Filament\Pages;

use App\Exports\ProductSalesReportExport;
use App\Models\Booking;
use App\Models\FilmProduct;
use App\Models\Refund;
use App\Models\Store;
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
            && $user->hasMenuAccess(static::class);
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
                ->label('Cabang')
                ->placeholder('Semua cabang')
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
                ->log('Ekspor Penjualan Produk (' . $format . ')');
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

        // BUG DIPERBAIKI 2026-09-11 (ditemukan saat audit): halaman ini
        // SEBELUMNYA SAMA SEKALI TIDAK ADA scoping toko (bahkan tidak
        // ada pengecekan auth()->user() sama sekali) — manajer toko
        // manapun melihat breakdown SKU company-wide.
        // GAP DIPERBAIKI 2026-09-29: full-access sebelumnya tidak bisa mempersempit ke 1 cabang --
        // sekarang filter 'store_id' di form dipakai kalau full-access memilihnya.
        $storeId = $this->effectiveStoreId();

        $bookings = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->where('transaction_amount', '>', 0)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with(['filmProduct:id,sku,name,product_type', 'installers:id'])
            ->get(['id', 'transaction_amount', 'film_product_id', 'product_ppf', 'product_kaca_film', 'product_detailing', 'product_premium_wash']);

        // HPP + Komisi per booking (audit Majoo f14, "Laporan
        // per-layanan lengkap ... + HPP + Laba Kotor") -- "Laba Kotor"
        // di sini SAMA formula dengan SalesByPeriodReport (Penjualan −
        // Komisi − Refund − HPP), supaya istilah ini konsisten artinya
        // di seluruh laporan Penjualan, cuma levelnya per-SKU di sini.
        // Lihat BookingCogsService untuk rincian & batasan perkiraan HPP.
        // Komisi dihitung lewat Technician::commissionForBooking() (audit
        // Majoo f34, flat ATAU per-jenis-layanan).
        $cogsByBookingId = app(\App\Services\BookingCogsService::class)->forBookings($bookings->pluck('id')->all());
        $technicianByUserId = \App\Models\Technician::query()->whereNotNull('user_id')->with('serviceRates')->get()->keyBy('user_id');

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
            ->when($storeId, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $storeId)))
            ->with('booking:id,film_product_id')
            ->get(['amount', 'booking_id']);

        $refundByProductId = $refunds->groupBy(fn (Refund $r) => $r->booking?->film_product_id)
            ->map(fn ($group) => ['count' => $group->count(), 'amount' => (float) $group->sum('amount')]);

        $rows = $bookings->groupBy('film_product_id')
            ->map(function ($group, $filmProductId) use ($refundByProductId, $cogsByBookingId, $technicianByUserId) {
                $filmProduct = $group->first()->filmProduct;
                $refundRow = $refundByProductId->get($filmProductId ?: null, ['count' => 0, 'amount' => 0.0]);

                $cogs = 0.0;
                $hasMissingCost = false;
                $commission = 0.0;
                $hasUnratedJob = false;

                foreach ($group as $booking) {
                    $bookingCogs = $cogsByBookingId[$booking->id] ?? ['cost' => 0.0, 'hasMissingCost' => false];
                    $cogs += $bookingCogs['cost'];
                    if ($bookingCogs['hasMissingCost']) {
                        $hasMissingCost = true;
                    }

                    foreach ($booking->installers as $installer) {
                        $technician = $technicianByUserId->get($installer->id);
                        $bookingCommission = $technician?->commissionForBooking($booking);
                        if ($bookingCommission !== null) {
                            $commission += $bookingCommission;
                        } else {
                            $hasUnratedJob = true;
                        }
                    }
                }

                $revenue = (float) $group->sum('transaction_amount');

                return [
                    'product' => $filmProduct,
                    'sku' => $filmProduct?->sku ?? '—',
                    'name' => $filmProduct?->name ?? 'Belum Diisi SKU',
                    'type' => match ($filmProduct?->product_type) {
                        'window_film' => 'Kaca Film',
                        'ppf' => 'PPF',
                        'detailing' => 'Detailing',
                        'premium_wash' => 'Premium Wash',
                        'color_change' => 'Ganti Warna',
                        default => '—',
                    },
                    'count' => $group->count(),
                    'revenue' => $revenue,
                    'refundCount' => $refundRow['count'],
                    'refundAmount' => $refundRow['amount'],
                    'commission' => $commission,
                    'hasUnratedJob' => $hasUnratedJob,
                    'cogs' => $cogs,
                    'hasMissingCost' => $hasMissingCost,
                    'grossProfit' => $revenue - $commission - $refundRow['amount'] - $cogs,
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

        // Headline net + footnote (audit 2026-09-11) — SEBELUMNYA
        // "Total Penjualan Produk" gross dengan "Total Refund" sebagai
        // kartu terpisah (user harus hitung sendiri net-nya). Sekarang
        // konsisten dengan laporan Penjualan lain: headline = bersih.
        // Baris per-produk TETAP gross apa adanya (refund tidak selalu
        // bisa diatribusikan 1:1 ke SKU kalau booking-nya "Belum Diisi
        // SKU"), kolom "Refund" per baris tetap ada untuk transparansi.
        $totalRefundAmount = (float) $refunds->sum('amount');

        return [
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
            'rows' => $rows,
            'totalCount' => $totalCount,
            'totalRevenue' => $totalRevenue - $totalRefundAmount,
            'grossRevenue' => $totalRevenue,
            'totalRefundAmount' => $totalRefundAmount,
            'unassignedCount' => $unassignedCount,
            'unassignedPct' => $totalCount > 0 ? $unassignedCount / $totalCount * 100 : 0,
            'totalCogs' => $rows->sum('cogs'),
            'totalCommission' => $rows->sum('commission'),
            'totalGrossProfit' => $rows->sum('grossProfit'),
            'hasMissingCost' => $rows->contains('hasMissingCost', true),
        ];
    }
}
