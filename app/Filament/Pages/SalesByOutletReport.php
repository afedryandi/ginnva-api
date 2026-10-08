<?php

namespace App\Filament\Pages;

use App\Exports\SalesByOutletExport;
use App\Models\Booking;
use App\Models\Refund;
use App\Models\Store;
use App\Services\SalesSnapshotService;
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
 * Agregasi SQL (audit 2026-09-11, temuan H) — SEBELUMNYA getResult()
 * loop tiap toko lalu ->get() booking terpisah (N query, N = jumlah
 * toko). Sekarang 1 query GROUP BY store_id (COUNT/SUM/CASE), DB yang
 * hitung — pola sama SalesSnapshotService/SalesDetailStatsWidget.
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

    // #[Url] (audit 2026-09-11, temuan D) — pola sama laporan Penjualan
    // lain: filter disimpan di query string supaya link bisa
    // di-bookmark/dibagikan & bertahan lewat refresh.
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

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

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Penjualan Outlet 2026-09-29):
        // dikoreksi diam-diam di sini (kunjungan awal, belum ada UI untuk menampilkan notifikasi).
        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
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
            default => null,
        };

        // "Sampai" sebelum "Dari" (audit Penjualan Outlet 2026-09-29): sebelumnya diam-diam
        // menghasilkan tabel Rp 0 tanpa penjelasan -- dikoreksi + diberi tahu, sama pola dengan
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
        ])->columns(3)->statePath('data');
    }

    /** Link drill-down ke Detail Penjualan untuk 1 outlet pada rentang yang sedang dilihat. */
    public function salesUrl(int $storeId): string
    {
        return \App\Filament\Resources\SalesResource::getUrl('index', [
            'tableFilters' => [
                'entry_date' => ['from' => $this->data['from'] ?? null, 'until' => $this->data['to'] ?? null],
                'store_id' => ['value' => $storeId],
            ],
        ]);
    }

    /**
     * Toko yang BENAR-BENAR berlaku: full-access melihat semua outlet (null), staf toko hanya tokonya.
     * Staf tanpa toko dikunci ke -1 (tidak cocok toko mana pun), BUKAN null -- null akan menampilkan daftar
     * semua outlet (dengan angka nol) kepada akun yang tidak punya cakupan toko.
     */
    private function effectiveStoreId(): ?int
    {
        $user = auth()->user();

        if ($user?->isFullAccess() ?? false) {
            return null;
        }

        return $user?->store_id ?? -1;
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-11, temuan B) — pola sama laporan
     * Penjualan lain, dibangun dari getResult() yang sama dipakai layar.
     */
    /** Log ekspor (audit Penjualan Outlet 2026-09-29), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'sales_by_outlet', 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'store_id' => $this->effectiveStoreId()])
                ->log('Ekspor Penjualan Outlet (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }

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
                        new SalesByOutletExport($this->getResult()),
                        'penjualan-outlet-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.sales_by_outlet', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'penjualan-outlet-' . now()->format('Ymd-His') . '.pdf';

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

        // Refund per toko -- SAMA definisi dengan seluruh laporan
        // Penjualan lain: dikelompokkan berdasarkan created_at refund itu
        // sendiri (kapan DIPROSES), bukan tanggal booking-nya.
        $refundByStoreId = Refund::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($storeId, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $storeId)))
            ->with('booking:id,store_id')
            ->get(['amount', 'booking_id', 'created_at'])
            ->groupBy(fn (Refund $r) => $r->booking?->store_id)
            ->map(fn ($group) => (float) $group->sum('amount'));

        // 1 query agregat GROUP BY store_id (audit 2026-09-11, temuan H)
        // -- gantikan loop N query per toko.
        $aggByStoreId = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->where('transaction_amount', '>', 0)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->groupBy('store_id')
            ->selectRaw(
                'store_id,'
                . ' COUNT(*) as cnt,'
                . ' COALESCE(SUM(transaction_amount), 0) as revenue,'
                . ' COALESCE(SUM(COALESCE(amount_received, transaction_amount)), 0) as received,'
                // Keempat jenis layanan (sama dengan "Produk Terjual" di Dashboard & Booking::salesProductCount()).
                . ' COALESCE(SUM(COALESCE(product_kaca_film, 0) + COALESCE(product_ppf, 0) + COALESCE(product_detailing, 0) + COALESCE(product_premium_wash, 0)), 0) as products'
            )
            ->toBase()
            ->get()
            ->keyBy('store_id');

        $stores = Store::query()
            ->when($storeId, fn ($q) => $q->where('id', $storeId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Store $store) use ($aggByStoreId, $refundByStoreId) {
                $agg = $aggByStoreId->get($store->id);
                $count = (int) ($agg->cnt ?? 0);
                $grossRevenue = (float) ($agg->revenue ?? 0);
                $received = (float) ($agg->received ?? 0);
                $products = (int) ($agg->products ?? 0);
                $refund = (float) ($refundByStoreId[$store->id] ?? 0);
                $revenue = $grossRevenue - $refund;

                return [
                    'store' => $store,
                    'count' => $count,
                    'grossRevenue' => $grossRevenue,
                    'refund' => $refund,
                    'revenue' => $revenue,
                    'received' => $received,
                    'outstanding' => max(0, $grossRevenue - $received),
                    'avg' => $count > 0 ? $revenue / $count : 0,
                    'products' => $products,
                    'productsPerTransaction' => $count > 0 ? $products / $count : 0,
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
            'storeId' => $storeId,
            'rows' => $stores,
            'totalRevenue' => $totalRevenue,
            'totalRefund' => $totalRefund,
            'totalCount' => $totalCount,
            'totalProducts' => $totalProducts,
            // Banner "booking selesai belum diproses" (audit 2026-09-11,
            // temuan C) — sama konsep dengan laporan Penjualan lain.
            'pendingCount' => app(SalesSnapshotService::class)->pendingCount($storeId),
        ];
    }
}
