<?php

namespace App\Filament\Pages;

use App\Exports\PeakSalesTimeReportExport;
use App\Models\Booking;
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
     * Penjualan lain.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new PeakSalesTimeReportExport($this->getResult()),
                    'waktu-teramai-penjualan-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.peak_sales_time_report', ['result' => $result])->setPaper('a4', 'portrait');
                    $filename = 'waktu-teramai-penjualan-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $user = auth()->user();
        $storeId = ($user?->isFullAccess() ?? false) ? null : $user?->store_id;

        $bookings = Booking::query()
            ->whereHas('journalEntry', fn ($q) => $q->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()]))
            ->where('transaction_amount', '>', 0)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->with('journalEntry:id,entry_date')
            ->get(['id', 'transaction_amount', 'journal_entry_id', 'product_kaca_film', 'product_ppf', 'customer_id']);

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

            $days[$day]['revenue'] += (float) $booking->transaction_amount;
            $days[$day]['count']++;
            $days[$day]['products'] += ($booking->product_kaca_film ? 1 : 0) + ($booking->product_ppf ? 1 : 0);
            if ($booking->customer_id) {
                $days[$day]['customers'][$booking->customer_id] = true;
            }
        }

        $totalRevenue = (float) $bookings->sum('transaction_amount');
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
        ];
    }
}
