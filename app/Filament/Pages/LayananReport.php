<?php

namespace App\Filament\Pages;

use App\Exports\LayananReportExport;
use App\Models\Booking;
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
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * "Laporan Jasa" — diminta 2026-09-08, analog "Laporan Jasa" Majoo
 * (rekap servis terjual per jenis/periode). Ginnva bisnis JASA instalasi
 * (bukan jual barang eceran), jadi "jasa" di sini = booking yang sudah
 * tercatat sebagai pendapatan (sama filter dengan SalesResource/
 * BookingRevenueStatsWidget — whereHas('journalEntry'), supaya SELALU
 * konsisten dengan Jurnal Umum). Beda dari SalesResource (daftar
 * transaksi satu-satu): ini AGREGAT per jenis servis & per toko, sama
 * pola report Keuangan (custom Page + form rentang tanggal + Blade
 * view). Pembagian booking multi-jenis SAMA PERSIS dengan
 * BookingRevenueSplitter (Jurnal Umum).
 */
class LayananReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    // Grup sendiri 'Laporan Jasa' (diubah 2026-09-09 dari 'Laporan'
    // gabungan) -- sejajar dengan grup kategori laporan lain, bukan
    // nested (Filament v3 tidak dukung dropdown bersarang). Dashboard
    // Penjualan (SalesDashboard) SENGAJA tidak ikut grup manapun, tetap
    // berdiri sendiri di atas semua grup.
    protected static ?string $navigationGroup = 'Laporan Jasa';

    protected static ?string $navigationLabel = 'Laporan Jasa';

    protected static ?string $title = 'Laporan Jasa';

    // 100 -- band grup 'Laporan Jasa' (lihat catatan sistem band di
    // ProductSalesReport.php, diperbaiki 2026-09-09).
    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.layanan-report';

    public ?array $data = [];

    // #[Url] (audit 2026-09-11, temuan D) — pola sama laporan Penjualan
    // lain. Property ini di kelas dasar berlaku juga untuk JenisOrderReport
    // (extends penuh), jadi satu implementasi dua halaman.
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'cabang')]
    public ?int $storeIdFilter = null;

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

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Laporan Jasa/Jenis Order 2026-09-29):
        // dikoreksi diam-diam di sini, sama pola dengan laporan Penjualan lain.
        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeIdFilter = null;
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'store_id' => $this->storeIdFilter,
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
            'store_id' => $this->storeIdFilter = $value ? (int) $value : null,
            default => null,
        };

        // "Sampai" sebelum "Dari" (audit Laporan Jasa/Jenis Order 2026-09-29): sebelumnya diam-diam
        // menghasilkan tabel kosong tanpa penjelasan -- dikoreksi + diberi tahu, sama pola dengan
        // laporan Penjualan lain. TIDAK pakai minDate() reaktif di form (pernah membuat panel filter
        // gagal render di Detail Penjualan) -- validasi murni lewat hook Livewire ini.
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
            Select::make('store_id')
                ->label('Toko')
                ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                ->searchable()
                ->placeholder('Semua Toko')
                ->live()
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false),
        ])->columns(4)->statePath('data');
    }

    /** Link drill-down ke Detail Penjualan untuk toko tertentu (audit 2026-09-29). */
    public function salesUrl(string $storeName): string
    {
        $store = Store::query()->where('name', $storeName)->first(['id']);

        return \App\Filament\Resources\SalesResource::getUrl('index', [
            'tableFilters' => [
                'entry_date' => ['from' => $this->data['from'] ?? null, 'until' => $this->data['to'] ?? null],
                'store_id' => $store ? ['value' => $store->id] : null,
            ],
        ]);
    }

    /**
     * Toko yang BENAR-BENAR berlaku: full-access memilih (null = semua toko), staf toko dikunci ke tokonya, dan
     * staf tanpa toko dikunci ke -1 (tidak cocok toko mana pun) -- bukan null yang berarti semua toko.
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

    /** Log ekspor (audit Laporan Jasa/Jenis Order 2026-09-29), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => Str::slug(static::$navigationLabel ?? 'laporan-jasa'), 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'store_id' => $this->effectiveStoreId()])
                ->log('Ekspor ' . (static::$navigationLabel ?? 'Laporan Jasa') . ' (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-11, temuan B) — filename & judul
     * ikut halaman aktif (static::$navigationLabel) supaya export dari
     * "Laporan Jenis Order" tidak keliru bertuliskan "Laporan Jasa"
     * walau logic-nya sama persis.
     */
    protected function getHeaderActions(): array
    {
        $slug = Str::slug(static::$navigationLabel ?? 'laporan-jasa');

        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () use ($slug) {
                    $this->logExport('xlsx');

                    return Excel::download(
                        new LayananReportExport($this->getResult(), static::$navigationLabel ?? 'Laporan Jasa'),
                        $slug . '-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () use ($slug) {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.layanan_report', [
                        'result' => $result,
                        'title' => static::$navigationLabel ?? 'Laporan Jasa',
                    ])->setPaper('a4', 'portrait');
                    $filename = $slug . '-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        // startOfDay(): nilai DatePicker bisa membawa jam; tanpa ini refund sebelum jam itu di hari pertama tidak terhitung.
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        // storeId efektif dipakai untuk booking DAN refund supaya
        // keduanya konsisten scope ke cabang yang sama.
        $storeId = $this->effectiveStoreId();

        $query = Booking::query()
            ->with('store')
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->where('transaction_amount', '>', 0)
            // store_id di bookings NOT NULL (migrasi create_bookings_table)
            // — orWhereNull() dulu di sini itu dead code, dibersihkan
            // 2026-09-11 (audit) sama pola dengan P1 SalesDashboard.
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId));

        $bookings = $query->get(['id', 'store_id', 'transaction_amount', 'product_kaca_film', 'product_ppf', 'product_detailing', 'product_premium_wash']);

        // BUG DIPERBAIKI 2026-09-11 (ditemukan saat audit): "Total
        // Pendapatan" SEBELUMNYA gross, tidak dikurangi refund — beda
        // dari Dashboard/Ringkasan/Per Periode/Outlet yang konsisten
        // pakai angka bersih. Refund TIDAK tertaut ke jenis produk
        // tertentu (cuma ke booking), jadi TIDAK didistribusikan ke
        // byType/byStore (itu tetap gross apa adanya per baris) — cukup
        // dikurangkan di headline "Total Pendapatan" + footnote gross,
        // pola sama SalesDashboard.
        $refundTotal = (float) Refund::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($storeId, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $storeId)))
            ->sum('amount');

        // Empat jenis layanan + 'lainnya' (booking tanpa jenis sama sekali). Pembagiannya DIAMBIL dari
        // BookingRevenueSplitter::shares() -- aturan yang sama dengan Jurnal Umum (bagi rata kalau lebih dari satu jenis),
        // jadi persentase selalu berjumlah 100% dan tidak menyimpang dari pembukuan.
        $byType = [
            'kaca_film' => ['count' => 0, 'revenue' => 0.0],
            'ppf' => ['count' => 0, 'revenue' => 0.0],
            'detailing' => ['count' => 0, 'revenue' => 0.0],
            'premium_wash' => ['count' => 0, 'revenue' => 0.0],
            'lainnya' => ['count' => 0, 'revenue' => 0.0],
        ];
        $byStore = [];
        $totalRevenue = 0.0;

        foreach ($bookings as $booking) {
            $amount = (float) $booking->transaction_amount;
            $totalRevenue += $amount;
            foreach (\App\Services\BookingRevenueSplitter::shares($booking, $amount) as $typeKey => $portion) {
                $byType[$typeKey]['count']++;
                $byType[$typeKey]['revenue'] += $portion;
            }

            $storeName = $booking->store?->name ?? 'Tanpa Toko';
            $byStore[$storeName] ??= ['count' => 0, 'revenue' => 0.0];
            $byStore[$storeName]['count']++;
            $byStore[$storeName]['revenue'] += $amount;
        }

        uasort($byStore, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        // Persentase (diminta 2026-09-09, analog "Jumlah Transaksi %"/
        // "Penjualan %" di Laporan Jenis Order Majoo). Penyebut jumlah
        // pakai TOTAL jumlah slot jenis (bukan totalCount) karena
        // booking Kaca Film+PPF terhitung di KEDUA jenis (bukan cuma 1
        // booking 1 jenis kayak "Jenis Order" Majoo) -- supaya 2
        // persentase count tetap jumlah 100%, bukan 200%. Penyebut
        // revenue pakai totalRevenue asli (split 50/50 sudah pas jumlah
        // ke totalRevenue, tidak ada double count).
        $typeCountTotal = array_sum(array_column($byType, 'count'));
        foreach ($byType as $key => $row) {
            $byType[$key]['countPct'] = $typeCountTotal > 0 ? $row['count'] / $typeCountTotal * 100 : 0;
            $byType[$key]['revenuePct'] = $totalRevenue > 0 ? $row['revenue'] / $totalRevenue * 100 : 0;
        }

        $netRevenue = $totalRevenue - $refundTotal;

        return [
            'from' => $from,
            'to' => $to,
            'totalCount' => $bookings->count(),
            'totalRevenue' => $netRevenue,
            'grossRevenue' => $totalRevenue,
            'refund' => $refundTotal,
            'avgRevenue' => $bookings->count() > 0 ? $netRevenue / $bookings->count() : 0,
            'byType' => $byType,
            'byStore' => $byStore,
        ];
    }
}
