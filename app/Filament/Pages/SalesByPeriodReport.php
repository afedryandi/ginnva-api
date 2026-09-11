<?php

namespace App\Filament\Pages;

use App\Exports\SalesByPeriodExport;
use App\Models\Booking;
use App\Models\Refund;
use App\Models\Technician;
use App\Services\SalesSnapshotService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
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

        if (! in_array($this->granularity, ['harian', 'mingguan', 'bulanan'], true)) {
            $this->granularity = 'harian';
        }

        $this->form->fill([
            'from' => $this->from,
            'to' => $this->to,
            'granularity' => $this->granularity,
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
            default => null,
        };
    }

    public function form(Form $form): Form
    {
        return $form->schema([
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
        ])->columns(3)->statePath('data');
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-11, temuan B) — Excel & PDF,
     * keduanya dibangun dari getResult() yang SAMA PERSIS dipakai
     * halaman web, pola sama dengan SalesSummaryReport/SalesResource.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new SalesByPeriodExport($this->getResult()),
                    'penjualan-per-periode-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
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

        // BUG DIPERBAIKI 2026-09-11 (ditemukan saat audit): halaman ini
        // SEBELUMNYA SAMA SEKALI TIDAK ADA scoping toko — bookings MAUPUN
        // refund — manajer toko manapun melihat rekap company-wide. Sama
        // pola scoping dengan seluruh laporan Penjualan lain: null =
        // seluruh cabang (full-access saja), staff toko dikunci ke
        // store_id sendiri.
        $user = auth()->user();
        $storeId = ($user?->isFullAccess() ?? false) ? null : $user?->store_id;

        $bookings = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->where('transaction_amount', '>', 0)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with(['journalEntry:id,entry_date', 'installers:id'])
            ->get(['id', 'transaction_amount', 'amount_received', 'journal_entry_id', 'product_kaca_film', 'product_ppf']);

        // commission_amount per user_id teknisi -- dipakai hitung kolom
        // "Komisi", nominal TETAP per pekerjaan, FULL ke masing-masing
        // teknisi (bukan dibagi), sama aturan yang dikonfirmasi user di
        // TechnicianCommissionReport. Teknisi tanpa commission_amount
        // (NULL) TIDAK ikut disumkan -- ditandai lewat $hasUnratedJob
        // per bucket supaya angka Komisi tidak menyesatkan seolah sudah
        // final untuk semua booking.
        $commissionByUserId = Technician::query()
            ->whereNotNull('user_id')
            ->pluck('commission_amount', 'user_id');

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
                $rate = $commissionByUserId[$installer->id] ?? null;
                if ($rate !== null) {
                    $buckets[$key]['commission'] += (float) $rate;
                } elseif ($booking->installers->isNotEmpty()) {
                    $buckets[$key]['hasUnratedJob'] = true;
                }
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

        return [
            'from' => $from,
            'to' => $to,
            'granularity' => $granularity,
            'storeId' => $storeId,
            'rows' => $buckets,
            'totalRevenue' => array_sum(array_column($buckets, 'revenue')),
            'totalCount' => array_sum(array_column($buckets, 'count')),
            'totalProducts' => array_sum(array_column($buckets, 'products')),
            // Banner "booking selesai belum diproses" (audit 2026-09-11,
            // temuan C) — sama konsep dgn laporan Penjualan lain.
            'pendingCount' => app(SalesSnapshotService::class)->pendingCount($storeId),
            // Guard rentang sangat panjang (audit 2026-09-11, temuan G) —
            // advisory saja, TIDAK memotong data.
            'tooManyBuckets' => count($buckets) > self::TOO_MANY_BUCKETS_THRESHOLD,
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
            'mingguan' => (function () use ($date) {
                $start = $date->copy()->startOfWeek();
                $end = $date->copy()->endOfWeek();

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
