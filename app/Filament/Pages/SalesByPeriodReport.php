<?php

namespace App\Filament\Pages;

use App\Exports\SalesByPeriodExport;
use App\Models\Booking;
use App\Models\Refund;
use App\Models\Store;
use App\Models\Technician;
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
 * "Penjualan Per Periode" — diminta 2026-09-09, analog "Penjualan Per
 * Periode" Majoo. BEDA dari toggle Harian/Mingguan/Bulanan di Dashboard
 * (yang cuma tampilkan 1 angka ringkas untuk 1 periode aktif + periode
 * sebelumnya buat perbandingan) — halaman ini TABEL REKAP tiap baris =
 * 1 periode, supaya kelihatan tren/perbandingan lintas beberapa periode
 * sekaligus dalam rentang tanggal bebas.
 *
 * Sumber & logika pendapatan SAMA PERSIS dengan seluruh laporan
 * Penjualan lain (whereHas('journalEntry'), transaction_amount > 0,
 * amount_received NULL = lunas penuh) — satu sumber kebenaran.
 *
 * Scoping toko (audit 2026-09-11): staff toko dikunci ke store_id
 * sendiri, full-access lihat seluruh cabang — lihat getResult().
 */
class SalesByPeriodReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $cluster = \App\Filament\Clusters\PenjualanCluster::class;

    protected static ?string $navigationGroup = 'Laporan Penjualan';

    protected static ?string $navigationLabel = 'Penjualan Per Periode';

    protected static ?string $title = 'Penjualan Per Periode';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.sales-by-period-report';

    public ?array $data = [];

    // Baris tabel di atas 200 pakai granularitas Harian + rentang sangat
    // panjang jadi berat dibaca/di-render (audit 2026-09-11, temuan G) —
    // bukan hard limit (tetap dihitung & dirender), cuma jadi ambang
    // munculnya banner saran "pakai granularitas lebih kasar".
    private const TOO_MANY_BUCKETS_THRESHOLD = 200;

    // Lihat komentar di getResult() -- cap ini beda dari
    // TOO_MANY_BUCKETS_THRESHOLD (itu cuma saran UI granularitas).
    private const MAX_RANGE_DAYS = 730;

    // #[Url] (audit 2026-09-11, temuan D) — sama pola dengan
    // SalesDashboard/SalesSummaryReport: filter disimpan di query string
    // supaya link bisa di-bookmark/dibagikan & bertahan lewat refresh.
    // Property TERPISAH dari $data (dipakai Filament Form) — disinkronkan
    // mount() (URL->form) & updatedData() (form->URL).
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    #[Url(as: 'granularitas')]
    public ?string $granularity = null;

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
        $this->from = $this->queryDateOrDefault($this->from, now()->startOfMonth()->subMonthsNoOverflow(2));
        $this->to = $this->queryDateOrDefault($this->to, now()->endOfMonth());

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Penjualan Per Periode 2026-09-29):
        // dikoreksi diam-diam di sini (kunjungan awal, belum ada UI untuk menampilkan notifikasi).
        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        if (! in_array($this->granularity, ['harian', 'mingguan', 'bulanan'], true)) {
            $this->granularity = 'harian';
        }

        // Filter cabang (audit 2026-09-29, sejajar SalesSummaryReport) -- staff toko TIDAK PERNAH
        // boleh pilih cabang lain, URL yang tidak sah diabaikan (defense in depth, sama pola dengan
        // SalesSummaryReport::mount()).
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeId = null;
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'granularity' => $this->granularity,
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

    /**
     * Livewire lifecycle hook — cerminkan balik $data ke property #[Url]
     * tiap kali form berubah (form pakai ->live()), pelengkap arah
     * mount() di atas. Lihat pola sama di SalesSummaryReport.
     */
    public function updatedData(mixed $value, string $key): void
    {
        match ($key) {
            'from' => $this->from = $value,
            'to' => $this->to = $value,
            'granularity' => $this->granularity = $value,
            'store_id' => $this->storeId = $value ? (int) $value : null,
            default => null,
        };

        // "Sampai" sebelum "Dari" (audit Penjualan Per Periode 2026-09-29): sebelumnya diam-diam
        // menghasilkan tabel Rp 0 tanpa penjelasan -- dikoreksi + diberi tahu, sama pola dengan
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
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        return $form->schema([
            // Periode Cepat (audit 2026-09-29, sejajar laporan Penjualan lain) -- mengisi
            // Dari/Sampai otomatis; granularitas & cabang tetap dipilih terpisah.
            Select::make('preset')
                ->label('Periode Cepat')
                ->options([
                    'last_3_months' => '3 bulan terakhir',
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
                        'last_3_months' => [now()->startOfMonth()->subMonthsNoOverflow(2), now()->endOfMonth()],
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
            Select::make('granularity')
                ->label('Kelompokkan Per')
                ->options([
                    'harian' => 'Harian',
                    'mingguan' => 'Mingguan',
                    'bulanan' => 'Bulanan',
                ])
                ->required()
                ->default('harian')
                ->live(),
            // Filter cabang (audit 2026-09-29) -- cuma untuk full-access, sama pola dengan
            // SalesSummaryReport. Staff toko tidak lihat field ini sama sekali.
            Select::make('store_id')
                ->label('Cabang')
                ->placeholder('Semua cabang')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 5 : 4)->statePath('data');
    }

    /** Link drill-down ke Detail Penjualan untuk 1 baris periode. */
    public function salesUrl(string $bucketFrom, string $bucketTo): string
    {
        return \App\Filament\Resources\SalesResource::getUrl('index', [
            'tableFilters' => [
                'entry_date' => ['from' => $bucketFrom, 'until' => $bucketTo],
                'store_id' => ['value' => $this->data['store_id'] ?? null],
            ],
        ]);
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-11, temuan B) — Excel & PDF,
     * keduanya dibangun dari getResult() yang SAMA PERSIS dipakai
     * halaman web, pola sama dengan SalesSummaryReport/SalesResource.
     */
    /** Log ekspor (audit Penjualan Per Periode 2026-09-29), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'sales_by_period', 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'granularity' => $this->data['granularity'] ?? null, 'store_id' => $this->data['store_id'] ?? null])
                ->log('Ekspor Penjualan Per Periode (' . $format . ')');
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
                        new SalesByPeriodExport($this->getResult()),
                        'penjualan-per-periode-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.sales_by_period', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'penjualan-per-periode-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    /**
     * Grouping dilakukan di PHP (bukan GROUP BY SQL per minggu/bulan)
     * supaya label periode konsisten & mudah dibaca (mis. "01-07 Sep
     * 2026") tanpa tergantung dialect SQL (MySQL vs SQLite beda fungsi
     * tanggal). Jumlah booking dalam 1 rentang laporan biasanya kecil
     * (per toko per bulan), jadi ini tidak jadi masalah performa.
     */
    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $granularity = $this->data['granularity'] ?? 'harian';

        // Hard cap rentang tanggal (audit framework 2026-09-14,
        // "Agregasi laporan di level database") — laporan ini menarik
        // SEMUA baris booking+refund ke PHP lalu bucket manual per
        // periode (termasuk lookup komisi per teknisi), belum ditulis
        // ulang jadi SQL murni karena risiko salah hitung angka
        // finansial tanpa bisa diuji lokal. Cap ini MURNI jaring
        // pengaman volume data, beda dari TOO_MANY_BUCKETS_THRESHOLD di
        // atas (itu cuma saran UI, ini benar-benar membatasi data yang
        // ditarik dari database).
        $rangeClamped = false;
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = $to->copy()->subDays(self::MAX_RANGE_DAYS)->startOfDay();
            $rangeClamped = true;
        }

        // BUG DIPERBAIKI 2026-09-11 (ditemukan saat audit): halaman ini
        // SEBELUMNYA SAMA SEKALI TIDAK ADA scoping toko — bookings MAUPUN
        // refund — manajer toko manapun melihat rekap company-wide. Sama
        // pola scoping dengan seluruh laporan Penjualan lain: null =
        // seluruh cabang (full-access saja), staff toko dikunci ke
        // store_id sendiri.
        //
        // GAP DIPERBAIKI 2026-09-29: full-access sebelumnya tidak punya cara mempersempit ke 1
        // cabang di laporan ini (padahal Ringkasan Penjualan & Detail Penjualan sudah punya) --
        // sekarang filter 'store_id' di form dipakai kalau full-access memilihnya.
        $user = auth()->user();
        $isFullAccess = $user?->isFullAccess() ?? false;
        $storeId = $isFullAccess ? ($this->data['store_id'] ?? null) : $user?->store_id;

        $bookings = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->where('transaction_amount', '>', 0)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with(['journalEntry:id,entry_date', 'installers:id'])
            ->get(['id', 'transaction_amount', 'amount_received', 'journal_entry_id', 'product_kaca_film', 'product_ppf', 'product_detailing', 'product_premium_wash']);

        // Teknisi per user_id -- dipakai hitung kolom "Komisi" lewat
        // Technician::commissionForBooking() (audit Majoo f34: flat ATAU
        // per-jenis-layanan, tergantung apakah teknisi punya
        // serviceRates). Booking yang commissionForBooking()-nya null
        // (belum diatur utk jenis layanan itu) TIDAK ikut disumkan --
        // ditandai lewat $hasUnratedJob per bucket supaya angka Komisi
        // tidak menyesatkan seolah sudah final untuk semua booking.
        $technicianByUserId = Technician::query()
            ->whereNotNull('user_id')
            ->with('serviceRates')
            ->get()
            ->keyBy('user_id');

        // HPP per booking (audit Majoo f7, "Kolom Laba Kotor") — film +
        // bahan pendukung, lihat BookingCogsService untuk rincian &
        // batasan perkiraannya.
        $cogsByBookingId = app(\App\Services\BookingCogsService::class)->forBookings($bookings->pluck('id')->all());

        $buckets = [];

        // Inisialisasi semua slot periode dalam rentang DULU (bukan cuma
        // yang ada transaksinya) supaya periode kosong tetap muncul
        // sebagai Rp 0 — bukan hilang dari tabel, biar tren yang
        // sepi/kosong tetap kelihatan jelas.
        $cursor = $from->copy()->startOfDay();
        while ($cursor->lte($to)) {
            [$key, $label, $bucketEnd] = static::periodKeyFor($cursor, $granularity);
            $buckets[$key] ??= [
                'label' => $label, 'revenue' => 0.0, 'received' => 0.0, 'outstanding' => 0.0,
                'count' => 0, 'products' => 0, 'commission' => 0.0, 'hasUnratedJob' => false, 'refund' => 0.0,
                'cogs' => 0.0, 'hasMissingCost' => false,
                // Batas bucket ini (diclamp ke rentang laporan) -- dipakai drill-down ke Detail
                // Penjualan (audit 2026-09-29).
                'bucketFrom' => $cursor->copy()->toDateString(),
                'bucketTo' => ($bucketEnd->gt($to) ? $to : $bucketEnd)->toDateString(),
            ];
            $cursor = $bucketEnd->copy()->addDay();
        }

        foreach ($bookings as $booking) {
            $entryDate = $booking->journalEntry?->entry_date;
            if (! $entryDate) continue;

            [$key] = static::periodKeyFor(Carbon::parse($entryDate), $granularity);
            if (! isset($buckets[$key])) continue; // di luar rentang (harusnya tidak terjadi, jaring pengaman)

            $amount = (float) $booking->transaction_amount;
            $received = $booking->amount_received !== null ? (float) $booking->amount_received : $amount;

            $buckets[$key]['revenue'] += $amount;
            $buckets[$key]['received'] += $received;
            $buckets[$key]['outstanding'] += max(0, $amount - $received);
            $buckets[$key]['count']++;
            // "Produk" -- jumlah kategori produk (Kaca Film/PPF) yang
            // dipasang, SAMA pola dengan productsSold di SalesDashboard,
            // BUKAN jumlah SKU spesifik (film_product_id belum wajib
            // diisi, jadi belum bisa diandalkan untuk angka ini).
            $buckets[$key]['products'] += ($booking->product_kaca_film ? 1 : 0) + ($booking->product_ppf ? 1 : 0);

            foreach ($booking->installers as $installer) {
                $technician = $technicianByUserId->get($installer->id);
                $commission = $technician?->commissionForBooking($booking);
                if ($commission !== null) {
                    $buckets[$key]['commission'] += $commission;
                } else {
                    $buckets[$key]['hasUnratedJob'] = true;
                }
            }

            $cogs = $cogsByBookingId[$booking->id] ?? ['cost' => 0.0, 'hasMissingCost' => false];
            $buckets[$key]['cogs'] += $cogs['cost'];
            if ($cogs['hasMissingCost']) {
                $buckets[$key]['hasMissingCost'] = true;
            }
        }

        // Refund -- SEKARANG dihitung sungguhan (diminta 2026-09-09,
        // lihat RefundService). Dikelompokkan berdasarkan created_at
        // refund itu sendiri (kapan DIPROSES), bukan tanggal booking-nya.
        $refunds = Refund::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($storeId, fn ($q) => $q->whereHas('booking', fn ($q2) => $q2->where('store_id', $storeId)))
            ->get(['amount', 'created_at']);

        foreach ($refunds as $refund) {
            [$key] = static::periodKeyFor($refund->created_at, $granularity);
            if (! isset($buckets[$key])) continue;
            $buckets[$key]['refund'] += (float) $refund->amount;
        }

        // "Laba Kotor" = Penjualan − Komisi − Pengembalian − HPP (audit
        // Majoo f7) — dihitung SETELAH refund masuk supaya baris ini
        // tersedia untuk setiap bucket.
        foreach ($buckets as $key => $bucket) {
            $buckets[$key]['grossProfit'] = $bucket['revenue'] - $bucket['commission'] - $bucket['refund'] - $bucket['cogs'];
        }

        return [
            'from' => $from,
            'to' => $to,
            'granularity' => $granularity,
            'storeId' => $storeId,
            'rows' => $buckets,
            'totalRevenue' => array_sum(array_column($buckets, 'revenue')),
            'totalCount' => array_sum(array_column($buckets, 'count')),
            'totalProducts' => array_sum(array_column($buckets, 'products')),
            'totalCogs' => array_sum(array_column($buckets, 'cogs')),
            'totalGrossProfit' => array_sum(array_column($buckets, 'grossProfit')),
            // Banner "HPP belum lengkap" (audit Majoo f7) — muncul kalau
            // ADA booking dalam rentang ini yang pakai gulungan film
            // tanpa Harga Beli, atau bahan pendukung tanpa unit_cost.
            // Laba Kotor tetap ditampilkan (bukan disembunyikan), cuma
            // ditandai sebagai perkiraan minimum (HPP yang belum lengkap
            // dihitung Rp 0, jadi Laba Kotor sungguhan bisa lebih rendah).
            'hasMissingCost' => (bool) array_sum(array_map(fn ($b) => $b['hasMissingCost'] ? 1 : 0, $buckets)),
            // Banner "booking selesai belum diproses" (audit 2026-09-11,
            // temuan C) — sama konsep dgn laporan Penjualan lain.
            'pendingCount' => app(SalesSnapshotService::class)->pendingCount($storeId),
            // Guard rentang sangat panjang (audit 2026-09-11, temuan G) —
            // advisory saja, TIDAK memotong data.
            'tooManyBuckets' => count($buckets) > self::TOO_MANY_BUCKETS_THRESHOLD,
            'rangeClamped' => $rangeClamped,
        ];
    }

    /**
     * PUBLIC STATIC (audit 2026-09-11, temuan A) — dipakai ULANG oleh
     * SalesByPeriodChart supaya grafik mengelompokkan periode PERSIS sama
     * dengan tabel di bawahnya (sebelumnya masing-masing implementasi
     * sendiri: tabel per granularitas pilihan user, grafik selalu harian
     * 14/30/90 hari terakhir, terputus satu sama lain).
     *
     * @return array{0: string, 1: string, 2: Carbon} [key unik, label
     *         tampilan, tanggal akhir bucket ini]
     */
    public static function periodKeyFor(Carbon $date, string $granularity): array
    {
        return match ($granularity) {
            // Senin eksplisit (audit 2026-09-29) -- disamakan dengan SalesSnapshotService::range(),
            // supaya batas minggu tidak drift kalau konfigurasi locale/Carbon default berubah.
            'mingguan' => (function () use ($date) {
                $start = $date->copy()->startOfWeek(Carbon::MONDAY);
                $end = $date->copy()->endOfWeek(Carbon::SUNDAY);

                return [$start->toDateString(), $start->format('d M') . ' - ' . $end->format('d M Y'), $end];
            })(),
            'bulanan' => (function () use ($date) {
                $start = $date->copy()->startOfMonth();
                $end = $date->copy()->endOfMonth();

                return [$start->format('Y-m'), $start->translatedFormat('F Y'), $end];
            })(),
            default => [$date->toDateString(), $date->format('d M Y'), $date->copy()->endOfDay()],
        };
    }
}
