<?php

namespace App\Filament\Pages;

use App\Exports\PeakSalesTimeReportExport;
use App\Models\Booking;
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
 * "Waktu Teramai Penjualan" — diminta 2026-09-09, analog Majoo. DIBANGUN
 * ULANG 2026-09-09 setelah user tunjukkan screenshot Majoo yang
 * SEBENARNYA -- per HARI DALAM SEMINGGU (bukan per jam seperti versi
 * pertama), dari JournalEntry.entry_date (SELALU ada, beda dari
 * posted_at yang butuh timestamp jam:menit).
 *
 * "Pelanggan" (Tamu) dihitung dari customer_id UNIK per hari dalam
 * seminggu (bukan per tanggal kalender) -- customer yang sama datang
 * di 2 hari Senin berbeda (minggu berbeda) tetap dihitung 1x di baris
 * "Senin" (menjawab "hari apa paling banyak PELANGGAN BEDA datang",
 * bukan "berapa kunjungan total").
 */
class PeakSalesTimeReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $cluster = \App\Filament\Clusters\AnalisaLaporanCluster::class;

    protected static ?string $navigationLabel = 'Waktu Teramai Penjualan';

    protected static ?string $title = 'Waktu Teramai Penjualan';

    // 600 -- band grup 'Analisa Laporan' (lihat catatan sistem band di
    // ProductSalesReport.php).
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.peak-sales-time-report';

    private const DAY_NAMES = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', "Jum'at", 'Sabtu'];

    public ?array $data = [];

    // #[Url] (audit 2026-09-11, temuan D) — pola sama laporan Penjualan
    // lain.
    #[Url(as: 'from')]
    public ?string $from = null;

    #[Url(as: 'to')]
    public ?string $to = null;

    // Filter toko (audit 2026-09-30) -- sejajar laporan Penjualan lain.
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

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Waktu Teramai Penjualan
        // 2026-09-30): dikoreksi diam-diam di sini, sama pola dengan laporan Penjualan lain.
        if (Carbon::parse($this->to)->lt(Carbon::parse($this->from))) {
            $this->to = $this->from;
        }

        if (! (auth()->user()?->isFullAccess() ?? false)) {
            $this->storeId = auth()->user()?->store_id;
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

        // "Sampai" sebelum "Dari" (audit Waktu Teramai Penjualan 2026-09-30): sebelumnya diam-diam
        // menghasilkan tabel kosong tanpa penjelasan -- dikoreksi + diberi tahu, sama pola dengan
        // laporan Penjualan lain.
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
            // Periode Cepat (audit 2026-09-30, sejajar laporan Penjualan lain).
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

            // Filter toko (audit 2026-09-30) -- full-access sebelumnya SELALU company-wide
            // tanpa cara mempersempit ke 1 toko, beda dari laporan Penjualan/Persediaan lain.
            Select::make('store_id')
                ->label('Cabang')
                ->placeholder('Semua cabang')
                ->options(fn () => Store::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->visible($isFullAccess)
                ->live(),
        ])->columns($isFullAccess ? 4 : 3)->statePath('data');
    }

    /**
     * "Ekspor Laporan" (audit 2026-09-11, temuan B) — pola sama laporan
     * Penjualan lain.
     */
    /** Penjualan bersih sebuah booking: nilai transaksi dikurangi seluruh refund-nya (tidak negatif). */
    private function netSales(Booking $booking): float
    {
        return max(0.0, (float) $booking->transaction_amount - (float) $booking->refunds->sum('amount'));
    }

    /**
     * Toko yang BENAR-BENAR berlaku: full-access memilih (null = semua cabang), staf toko dikunci ke tokonya, dan
     * staf tanpa toko dikunci ke -1 (tidak cocok toko mana pun) -- bukan null yang berarti semua cabang.
     */
    private function effectiveStoreId(): ?int
    {
        $user = auth()->user();

        if ($user?->isFullAccess() ?? false) {
            return $this->storeId ?: null;
        }

        return $user?->store_id ?? -1;
    }

    /** Log ekspor (audit 2026-09-30), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'peak_sales_time', 'format' => $format, 'from' => $this->from, 'to' => $this->to, 'store_id' => $this->effectiveStoreId()])
                ->log('Ekspor Waktu Teramai Penjualan (' . $format . ')');
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
                        new PeakSalesTimeReportExport($this->getResult()),
                        'waktu-teramai-penjualan-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.peak_sales_time_report', ['result' => $result])->setPaper('a4', 'portrait');
                    $filename = 'waktu-teramai-penjualan-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    // Hard cap rentang tanggal (audit framework 2026-09-14, "Agregasi
    // laporan di level database") — laporan ini menarik SEMUA baris
    // booking ke PHP lalu bucket manual per hari-dalam-minggu (perlu
    // COUNT(DISTINCT customer_id) per hari, belum ditulis ulang jadi
    // SQL murni karena risiko salah hitung tanpa bisa diuji lokal).
    // Cap ini MURNI jaring pengaman volume data, tidak mengubah angka
    // hasil laporan untuk rentang normal.
    private const MAX_RANGE_DAYS = 730;

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();

        $rangeClamped = false;
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = $to->copy()->subDays(self::MAX_RANGE_DAYS)->startOfDay();
            $rangeClamped = true;
        }

        $storeId = $this->effectiveStoreId();

        $bookings = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->where('transaction_amount', '>', 0)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with(['journalEntry:id,entry_date', 'refunds:id,booking_id,amount'])
            ->get(['id', 'transaction_amount', 'journal_entry_id', 'product_kaca_film', 'product_ppf', 'product_detailing', 'product_premium_wash', 'customer_id']);

        $days = [];
        foreach (range(0, 6) as $d) {
            $days[$d] = [
                'dayName' => self::DAY_NAMES[$d],
                'revenue' => 0.0,
                'count' => 0,
                'products' => 0,
                'customers' => [], // pakai set (array key) supaya unik
            ];
        }

        foreach ($bookings as $booking) {
            $day = $booking->journalEntry?->entry_date?->dayOfWeek;
            if ($day === null) continue;

            $days[$day]['revenue'] += $this->netSales($booking);
            $days[$day]['count']++;
            // Keempat jenis layanan dihitung (PPF, Kaca Film, Detailing, Premium Wash) -- SEBELUMNYA hanya dua pertama,
            // jadi booking Detailing/Premium Wash tercatat 0 produk.
            $days[$day]['products'] += $booking->salesProductCount();
            if ($booking->customer_id) {
                $days[$day]['customers'][$booking->customer_id] = true;
            }
        }

        $totalRevenue = (float) $bookings->sum(fn (Booking $b) => $this->netSales($b));
        $totalCount = $bookings->count();
        $totalProducts = array_sum(array_column($days, 'products'));
        $totalCustomers = $bookings->pluck('customer_id')->filter()->unique()->count();

        $rows = collect($days)->map(function ($row) use ($totalRevenue, $totalCount, $totalProducts) {
            $customerCount = count($row['customers']);

            return [
                'dayName' => $row['dayName'],
                'revenue' => $row['revenue'],
                'revenuePct' => $totalRevenue > 0 ? $row['revenue'] / $totalRevenue * 100 : 0,
                'count' => $row['count'],
                'countPct' => $totalCount > 0 ? $row['count'] / $totalCount * 100 : 0,
                'products' => $row['products'],
                'productsPct' => $totalProducts > 0 ? $row['products'] / $totalProducts * 100 : 0,
                'customers' => $customerCount,
            ];
        })->values();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totalRevenue' => $totalRevenue,
            'totalCount' => $totalCount,
            'totalProducts' => $totalProducts,
            'totalCustomers' => $totalCustomers,
            'rangeClamped' => $rangeClamped,
        ];
    }
}
