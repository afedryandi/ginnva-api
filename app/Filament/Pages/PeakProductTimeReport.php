<?php

namespace App\Filament\Pages;

use App\Exports\PeakProductTimeReportExport;
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

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Waktu Teramai Produk
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

        // "Sampai" sebelum "Dari" (audit Waktu Teramai Produk 2026-09-30): sebelumnya diam-diam
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

    /** Log ekspor (audit 2026-09-30), konsisten dengan laporan lain. */
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

    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'peak_product_time', 'format' => $format, 'from' => $this->from, 'to' => $this->to, 'store_id' => $this->effectiveStoreId()])
                ->log('Ekspor Waktu Teramai Produk (' . $format . ')');
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
                        new PeakProductTimeReportExport($this->getResult()),
                        'waktu-teramai-produk-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.peak_product_time_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'waktu-teramai-produk-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    // Hard cap rentang tanggal (audit 2026-09-30, sejajar PeakSalesTimeReport) --
    // laporan ini menarik SEMUA baris booking ke PHP lalu bucket manual per
    // produk x hari-dalam-minggu. Cap ini MURNI jaring pengaman volume data,
    // tidak mengubah angka hasil laporan untuk rentang normal.
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
            // Produk terbanyak dulu; "Belum Diisi SKU" selalu di bawah; seri diurutkan SKU lalu hari supaya tampilan stabil.
            ->sort(function ($a, $b) {
                return [$a['product'] === null ? 1 : 0, -$a['count'], $a['product']?->sku ?? '', $a['day']]
                    <=> [$b['product'] === null ? 1 : 0, -$b['count'], $b['product']?->sku ?? '', $b['day']];
            })
            ->values();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totalCount' => $totalCount,
            'unassignedCount' => $unassignedCount,
            'rangeClamped' => $rangeClamped,
        ];
    }
}
