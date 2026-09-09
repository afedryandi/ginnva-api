<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\Technician;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

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

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->subMonthsNoOverflow(2)->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'granularity' => 'harian',
        ]);
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

        $bookings = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->where('transaction_amount', '>', 0)
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
            [$key, $label, $bucketEnd] = $this->periodKeyFor($cursor, $granularity);
            $buckets[$key] ??= [
                'label' => $label, 'revenue' => 0.0, 'received' => 0.0, 'outstanding' => 0.0,
                'count' => 0, 'products' => 0, 'commission' => 0.0, 'hasUnratedJob' => false,
            ];
            $cursor = $bucketEnd->copy()->addDay();
        }

        foreach ($bookings as $booking) {
            $entryDate = $booking->journalEntry?->entry_date;
            if (! $entryDate) continue;

            [$key] = $this->periodKeyFor(Carbon::parse($entryDate), $granularity);
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

        return [
            'from' => $from,
            'to' => $to,
            'granularity' => $granularity,
            'rows' => $buckets,
            'totalRevenue' => array_sum(array_column($buckets, 'revenue')),
            'totalCount' => array_sum(array_column($buckets, 'count')),
            'totalProducts' => array_sum(array_column($buckets, 'products')),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: Carbon} [key unik, label
     *         tampilan, tanggal akhir bucket ini]
     */
    private function periodKeyFor(Carbon $date, string $granularity): array
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
