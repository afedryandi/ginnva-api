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
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
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
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('from')->label('Dari')->native(false)->required()->live(),
            DatePicker::make('to')->label('Sampai')->native(false)->required()->live(),
        ])->columns(2)->statePath('data');
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-11, temuan B) — pola sama laporan
     * Penjualan lain, dibangun dari getResult() yang sama dipakai layar.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new SalesByOutletExport($this->getResult()),
                    'penjualan-outlet-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.sales_by_outlet', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'penjualan-outlet-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;
        $storeId = $isFullAccess ? null : $user?->store_id;

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
                . ' COALESCE(SUM(COALESCE(product_kaca_film, 0) + COALESCE(product_ppf, 0)), 0) as products'
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
