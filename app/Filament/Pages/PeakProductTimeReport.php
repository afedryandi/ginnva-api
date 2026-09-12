<?php

namespace App\Filament\Pages;

use App\Exports\PeakProductTimeReportExport;
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
 * "Waktu Teramai Produk" — diminta 2026-09-09, analog Majoo. DIBANGUN
 * ULANG 2026-09-09 setelah user tunjukkan screenshot Majoo yang
 * SEBENARNYA -- dipecah per PRODUK (SKU) × HARI DALAM SEMINGGU (bukan
 * per jam), pakai film_product_id (SAMA infrastruktur & keterbatasan
 * dengan ProductSalesReport: booking yang belum diisi SKU masuk bucket
 * "Belum Diisi SKU").
 *
 * "Hari" diambil dari JournalEntry.entry_date (tanggal booking BENAR-
 * BENAR tercatat sebagai pendapatan) -- SELALU ada untuk booking yang
 * dihitung di sini, beda dari analisa jam (posted_at) yang butuh
 * timestamp jam:menit lebih spesifik.
 */
class PeakProductTimeReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $cluster = \App\Filament\Clusters\AnalisaLaporanCluster::class;

    protected static ?string $navigationLabel = 'Waktu Teramai Produk';

    protected static ?string $title = 'Waktu Teramai Produk';

    // 601 -- band grup 'Analisa Laporan' (lihat catatan sistem band di
    // ProductSalesReport.php).
    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.peak-product-time-report';

    private const DAY_NAMES = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', "Jum'at", 'Sabtu'];

    public ?array $data = [];

    // #[Url] (audit 2026-09-12, temuan D) — pola sama laporan Penjualan
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
     * "Ekspor Laporan" (audit 2026-09-12, temuan B) — pola sama laporan
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
                    new PeakProductTimeReportExport($this->getResult()),
                    'waktu-teramai-produk-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.peak_product_time_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'waktu-teramai-produk-' . now()->format('Ymd-His') . '.pdf';

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
            ->with(['journalEntry:id,entry_date', 'filmProduct:id,sku,name'])
            ->get(['id', 'transaction_amount', 'journal_entry_id', 'film_product_id']);

        $totalCount = $bookings->count();
        $totalRevenue = (float) $bookings->sum('transaction_amount');
        $unassignedCount = $bookings->whereNull('film_product_id')->count();

        // Kelompok: [film_product_id, hari] => agregat
        $groups = [];
        foreach ($bookings as $booking) {
            $day = $booking->journalEntry?->entry_date?->dayOfWeek;
            if ($day === null) continue;

            $key = ($booking->film_product_id ?? 'none') . '|' . $day;
            $groups[$key] ??= [
                'product' => $booking->filmProduct,
                'day' => $day,
                'count' => 0,
                'revenue' => 0.0,
            ];
            $groups[$key]['count']++;
            $groups[$key]['revenue'] += (float) $booking->transaction_amount;
        }

        $rows = collect($groups)
            ->map(function ($row) use ($totalCount, $totalRevenue) {
                $row['countPct'] = $totalCount > 0 ? $row['count'] / $totalCount * 100 : 0;
                $row['revenuePct'] = $totalRevenue > 0 ? $row['revenue'] / $totalRevenue * 100 : 0;
                $row['dayName'] = self::DAY_NAMES[$row['day']];

                return $row;
            })
            ->sortByDesc(fn ($row) => $row['product'] === null ? -1 : $row['count'])
            ->values();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totalCount' => $totalCount,
            'unassignedCount' => $unassignedCount,
        ];
    }
}
