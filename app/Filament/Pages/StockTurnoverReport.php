<?php

namespace App\Filament\Pages;

use App\Exports\StockTurnoverReportExport;
use App\Models\ConsumableItem;
use App\Models\RawMaterial;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * "Perputaran Stok" -- Rasio Perputaran = pemakaian / rata-rata stok, per Bahan Baku dan Barang Habis Pakai (stok nasional, bukan per toko)
 * dalam satu rentang tanggal, dari RawMaterialMovement/ConsumableItemMovement.
 *
 * Pemakaian = pergerakan keluar dikurangi pengembalian memo (pergerakan masuk bersumber memo). Rata-rata stok = (stok awal + stok akhir) / 2;
 * stok awal dan akhir direkonstruksi mundur dari stok SAAT INI karena sistem tidak menyimpan snapshot harian. Rasio tinggi = cepat berputar
 * (bukan otomatis baik atau buruk). "Hari Terpakai" = jumlah hari berbeda yang punya pergerakan keluar.
 *
 * Estimasi Habis memakai konsumsi harian dari hari yang SUDAH berjalan (rentang dipotong sampai hari ini). Barang bersisa stok yang tidak
 * dipakai sama sekali dapat ditampilkan lewat pilihan "Tampilkan yang tidak terpakai" (stok mati).
 */
class StockTurnoverReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $cluster = \App\Filament\Clusters\AnalisaLaporanCluster::class;

    protected static ?string $navigationLabel = 'Perputaran Stok';

    protected static ?string $title = 'Perputaran Stok';

    // 602 -- band grup 'Analisa Laporan' (lihat catatan sistem band di
    // ProductSalesReport.php).
    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.stock-turnover-report';

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

        // Eksplisit (bukan akses bawaan): akun dengan menu kosong tidak ikut (audit Daftar Stok 2026-11-03).
        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasExplicitMenuAccess(static::class);
    }

    public function mount(): void
    {
        $this->from = $this->queryDateOrDefault($this->from, now()->startOfMonth());
        $this->to = $this->queryDateOrDefault($this->to, now()->endOfMonth());

        // "Sampai" < "Dari" via URL diutak-atik manual (audit Perputaran Stok
        // 2026-09-30): dikoreksi diam-diam di sini, sama pola dengan laporan Penjualan lain.
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

        // "Sampai" sebelum "Dari" (audit Perputaran Stok 2026-09-30): sebelumnya diam-diam
        // menghasilkan rekonstruksi stok yang salah tanpa penjelasan -- dikoreksi + diberi tahu,
        // sama pola dengan laporan Penjualan lain.
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

            Select::make('type')
                ->label('Jenis')
                ->options(['raw' => 'Bahan Baku', 'consumable' => 'Barang Habis Pakai'])
                ->placeholder('Semua jenis')
                ->live(),

            Toggle::make('only_low')
                ->label('Hanya yang hampir habis (' . self::LOW_DAYS . ' hari atau kurang)')
                ->live(),

            Toggle::make('show_idle')
                ->label('Tampilkan yang tidak terpakai')
                ->helperText('Barang yang masih ada stoknya tetapi tidak dipakai pada rentang ini (stok mati).')
                ->live(),
        ])->columns(3)->statePath('data');
    }

    /** Link drill-down ke halaman edit Bahan Baku/Barang Habis Pakai terkait (audit 2026-09-30). */
    public function itemUrl(string $type, int $id): string
    {
        return $type === 'Bahan Baku'
            ? \App\Filament\Resources\RawMaterialResource::getUrl('view', ['record' => $id])
            : \App\Filament\Resources\ConsumableItemResource::getUrl('view', ['record' => $id]);
    }

    /** Log ekspor (audit 2026-09-30), konsisten dengan laporan lain. */
    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'stock_turnover', 'format' => $format, 'from' => $this->from, 'to' => $this->to])
                ->log('Export Perputaran Stok (' . $format . ')');
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
                        new StockTurnoverReportExport($this->getResult()),
                        'perputaran-stok-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.stock_turnover_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'perputaran-stok-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    /** Batas "hampir habis": estimasi hari stok habis sama dengan atau kurang dari ini. */
    public const LOW_DAYS = 14;

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth())->endOfDay();
        $type = $this->data['type'] ?? null;
        $showIdle = (bool) ($this->data['show_idle'] ?? false);
        $onlyLow = (bool) ($this->data['only_low'] ?? false);

        $materials = $type === 'consumable' ? collect() : RawMaterial::query()
            ->with(['movements' => fn ($q) => $q->where('created_at', '>=', $from)])
            ->orderBy('name')
            ->get()
            ->map(fn (RawMaterial $item) => $this->computeTurnover($item, 'Bahan Baku', $from, $to));

        $consumables = $type === 'raw' ? collect() : ConsumableItem::query()
            ->with(['movements' => fn ($q) => $q->where('created_at', '>=', $from)])
            ->orderBy('name')
            ->get()
            ->map(fn (ConsumableItem $item) => $this->computeTurnover($item, 'Barang Habis Pakai', $from, $to));

        $all = $materials->concat($consumables);
        $used = $all->filter(fn ($row) => $row['qtyOut'] > 0);
        $idle = $all->filter(fn ($row) => $row['qtyOut'] <= 0 && $row['currentStock'] > 0);
        $isLow = fn ($row) => $row['daysUntilStockout'] !== null && $row['daysUntilStockout'] <= self::LOW_DAYS;

        $rows = ($showIdle ? $used->concat($idle) : $used)
            ->when($onlyLow, fn ($c) => $c->filter($isLow))
            // Rasio tertinggi dulu; rasio tak terhitung (rata-rata stok 0) di bawah; seri diurutkan nama supaya tampilan stabil.
            ->sort(fn ($a, $b) => [-($a['turnoverRatio'] ?? -1), $a['item']->name] <=> [-($b['turnoverRatio'] ?? -1), $b['item']->name])
            ->values();

        $ratios = $used->pluck('turnoverRatio')->filter(fn ($r) => $r !== null);

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'usedCount' => $used->count(),
            'lowCount' => $used->filter($isLow)->count(),
            // Barang bersisa stok yang tidak dipakai sama sekali pada rentang ini (tampil bila pilihan "tidak terpakai" menyala).
            'idleCount' => $idle->count(),
            'avgRatio' => $ratios->isNotEmpty() ? (float) $ratios->avg() : null,
        ];
    }

    /**
     * @param RawMaterial|ConsumableItem $item
     */
    private function computeTurnover($item, string $type, Carbon $from, Carbon $to): array
    {
        $grossOut = (float) $item->movements
            ->where('type', 'out')
            ->where('created_at', '<=', $to)
            ->sum('quantity');

        // Bahan yang dikembalikan lewat memo (pergerakan masuk bersumber memo) mengurangi pemakaian: yang dipakai = diambil - dikembalikan.
        $returned = (float) $item->movements
            ->where('type', 'in')
            ->where('source_type', 'memo')
            ->where('created_at', '<=', $to)
            ->sum('quantity');
        $qtyOutInPeriod = max(0.0, $grossOut - $returned);

        // Rekonstruksi stok di AKHIR rentang: stok SEKARANG dikurangi
        // net movement SETELAH $to.
        $netAfterTo = $item->movements->where('created_at', '>', $to)
            ->sum(fn ($m) => $m->type === 'out' ? -(float) $m->quantity : (float) $m->quantity);
        $stockAtTo = (float) $item->current_stock - $netAfterTo;

        // Stok di AWAL rentang: stok akhir rentang DITAMBAH net movement
        // DI DALAM rentang (membalik in/out selama rentang itu).
        $netDuringPeriod = $item->movements
            ->whereBetween('created_at', [$from, $to])
            ->sum(fn ($m) => $m->type === 'out' ? -(float) $m->quantity : (float) $m->quantity);
        $stockAtFrom = $stockAtTo - $netDuringPeriod;

        $avgStock = ($stockAtFrom + $stockAtTo) / 2;
        $turnoverRatio = $avgStock > 0 ? $qtyOutInPeriod / $avgStock : null;

        $daysSold = $item->movements
            ->where('type', 'out')
            ->whereBetween('created_at', [$from, $to])
            ->map(fn ($m) => $m->created_at->toDateString())
            ->unique()
            ->count();

        // "Estimasi Hari Stok Habis" -- MELENGKAPI rasio perputaran yang melihat ke belakang. Kecepatan konsumsi = pemakaian seluruh
        // rentang dibagi jumlah HARI kalender yang sudah berjalan (bukan hanya hari yang ada pergerakan: hari sepi ikut dihitung).
        // Hari yang belum lewat TIDAK ikut: rentang bawaan sampai akhir bulan, dan membaginya dengan seluruh bulan pada pertengahan
        // bulan membuat konsumsi tampak jauh lebih kecil dan estimasi habis jauh lebih lama dari sebenarnya.
        // Diproyeksikan dari current_stock SEKARANG (bukan stok di akhir rentang laporan): "kapan habis dari SEKARANG".
        $periodEnd = $to->lt(now()) ? $to : now();
        $periodDays = max(1, (int) $from->copy()->startOfDay()->diffInDays($periodEnd->copy()->startOfDay()) + 1);
        $avgDailyConsumption = $qtyOutInPeriod / $periodDays;
        $currentStock = (float) $item->current_stock;
        $daysUntilStockout = $avgDailyConsumption > 0 ? $currentStock / $avgDailyConsumption : null;

        return [
            'item' => $item,
            'type' => $type,
            'qtyOut' => $qtyOutInPeriod,
            'returned' => $returned,
            'stockAtFrom' => max(0, $stockAtFrom),
            'stockAtTo' => max(0, $stockAtTo),
            'avgStock' => max(0, $avgStock),
            'turnoverRatio' => $turnoverRatio,
            'daysSold' => $daysSold,
            'currentStock' => $currentStock,
            'avgDailyConsumption' => $avgDailyConsumption,
            'daysUntilStockout' => $daysUntilStockout,
        ];
    }
}
