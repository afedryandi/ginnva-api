<?php

namespace App\Filament\Pages;

use App\Models\Store;
use App\Services\FinancialStatementService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Laba Rugi Bulanan (audit ACCURATE 5, tampilan multi periode): satu tahun penuh, satu kolom per bulan ditambah total, per akun dan per
 * kelompok, dari jurnal yang sudah diposting. Tiap bulan memakai FinancialStatementService::incomeStatement() yang sama dengan Laporan Laba Rugi,
 * jadi angka bulan ini selalu sama dengan laporan itu bila rentangnya dipilih satu bulan. Akses dan pembatasan toko sama dengan Laporan Laba Rugi.
 */
class MonthlyIncomeStatementReport extends Page implements HasForms
{
    use InteractsWithForms;
    use Concerns\ExportsReportTable;

    public const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    // Band 300-399 "Laporan Keuangan"; 300 s.d. 305 sudah dipakai laporan lain.
    protected static ?string $navigationGroup = 'Laporan Keuangan';

    protected static ?string $navigationLabel = 'Laba Rugi Bulanan';

    protected static ?string $title = 'Laba Rugi Bulanan';

    protected static ?int $navigationSort = 306;

    protected static string $view = 'filament.pages.monthly-income-statement-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->canAccessStaffArea()
                && $user->hasMenuAccess(static::class)
                && $user->hasModuleAction(static::class, 'view', false));
    }

    private function isRestricted(): bool
    {
        return ! (auth()->user()?->isFullAccess() ?? false);
    }

    public function mount(): void
    {
        $this->form->fill([
            'year' => now()->year,
            'store_id' => $this->isRestricted() ? auth()->user()?->store_id : null,
            'hide_zero' => true,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('year')
                    ->label('Tahun')
                    ->options(fn () => collect(range(now()->year, now()->year - 5))->mapWithKeys(fn (int $y) => [$y => (string) $y])->all())
                    ->required()
                    ->live(),

                Select::make('store_id')
                    ->label('Toko')
                    ->options(fn () => [FinancialStatementService::COMPANY_WIDE => 'Pusat / Tanpa Toko'] + Store::pluck('name', 'id')->all())
                    ->helperText('Memilih toko TIDAK mencakup jurnal pusat (tanpa toko, mis. gaji pusat/penyusutan) — pilih "Pusat / Tanpa Toko" untuk melihatnya, atau kosongkan untuk semua.')
                    ->placeholder('Semua Toko')
                    ->searchable()
                    ->live()
                    ->disabled(fn () => $this->isRestricted() && auth()->user()?->store_id !== null),

                Toggle::make('hide_zero')
                    ->label('Sembunyikan akun bernilai nol')
                    ->live()
                    ->inline(false),
            ])
            ->statePath('data')
            ->columns(3);
    }

    private function year(): int
    {
        $year = (int) ($this->data['year'] ?? now()->year);

        return $year >= 2000 && $year <= now()->year ? $year : now()->year;
    }

    private function storeId(): ?int
    {
        $user = auth()->user();

        if ($this->isRestricted() && $user?->store_id !== null) {
            return (int) $user->store_id;
        }

        return isset($this->data['store_id']) && $this->data['store_id'] !== '' ? (int) $this->data['store_id'] : null;
    }

    public function storeLabel(): string
    {
        $id = $this->storeId();

        return match (true) {
            $id === null => 'Semua Toko',
            $id === FinancialStatementService::COMPANY_WIDE => 'Pusat / Tanpa Toko',
            default => Store::whereKey($id)->value('name') ?? 'Toko #' . $id,
        };
    }

    /**
     * @return array{
     *   year: int,
     *   sections: array<string, array{label: string, rows: list<array{account: \App\Models\ChartOfAccount, months: list<float>, total: float}>, months: list<float>, total: float}>,
     *   profits: array<string, array{label: string, months: list<float>, total: float}>
     * }
     */
    public function getResult(): array
    {
        $year = $this->year();
        $service = app(FinancialStatementService::class);
        $storeId = $this->storeId();

        $sections = [];
        $profits = [
            'laba_kotor' => ['label' => 'Laba Kotor', 'months' => [], 'total' => 0.0],
            'laba_operasional' => ['label' => 'Laba Operasional', 'months' => [], 'total' => 0.0],
            'laba_sebelum_pajak' => ['label' => 'Laba Sebelum Pajak', 'months' => [], 'total' => 0.0],
            'laba_bersih' => ['label' => 'Laba Bersih', 'months' => [], 'total' => 0.0],
        ];

        for ($m = 1; $m <= 12; $m++) {
            $start = Carbon::create($year, $m, 1)->startOfDay();
            $result = $service->incomeStatement($start, $start->copy()->endOfMonth()->startOfDay(), $storeId);

            foreach ($result['sections'] as $type => $section) {
                $sections[$type] ??= ['label' => $section['label'], 'rows' => [], 'months' => array_fill(0, 12, 0.0), 'total' => 0.0];

                foreach ($section['rows'] as $row) {
                    $id = $row['account']->id;
                    $sections[$type]['rows'][$id] ??= ['account' => $row['account'], 'months' => array_fill(0, 12, 0.0), 'total' => 0.0];
                    $sections[$type]['rows'][$id]['months'][$m - 1] = (float) $row['amount'];
                    $sections[$type]['rows'][$id]['total'] += (float) $row['amount'];
                }

                $sections[$type]['months'][$m - 1] = (float) $section['total'];
                $sections[$type]['total'] += (float) $section['total'];
            }

            foreach (array_keys($profits) as $key) {
                $profits[$key]['months'][$m - 1] = (float) $result[$key];
                $profits[$key]['total'] += (float) $result[$key];
            }
        }

        foreach ($sections as $type => $section) {
            $rows = collect($section['rows'])->sortBy(fn (array $r) => $r['account']->code)->values();

            if ($this->data['hide_zero'] ?? true) {
                $rows = $rows->reject(fn (array $r) => abs($r['total']) < 0.005 && collect($r['months'])->every(fn (float $v) => abs($v) < 0.005))->values();
            }

            $sections[$type]['rows'] = $rows->all();
        }

        return ['year' => $year, 'sections' => $sections, 'profits' => $profits];
    }

    protected function reportExportData(): array
    {
        $result = $this->getResult();
        $blank = fn (float $v) => abs($v) < 0.005 ? null : round($v, 2);
        $cells = fn (array $months, float $total) => array_merge(array_map($blank, $months), [$blank($total)]);

        $rows = [];
        $profitAfter = ['beban_pokok' => 'laba_kotor', 'beban_operasional' => 'laba_operasional', 'beban_lain' => 'laba_sebelum_pajak', 'pajak' => 'laba_bersih'];

        foreach ($result['sections'] as $type => $section) {
            $rows[] = array_merge([$section['label'], ''], array_fill(0, 13, null));

            foreach ($section['rows'] as $row) {
                $rows[] = array_merge([$row['account']->code, $row['account']->name], $cells($row['months'], $row['total']));
            }

            $rows[] = array_merge(['', 'Total ' . $section['label']], $cells($section['months'], $section['total']));

            if (isset($profitAfter[$type])) {
                $profit = $result['profits'][$profitAfter[$type]];
                $rows[] = array_merge(['', mb_strtoupper($profit['label'])], $cells($profit['months'], $profit['total']));
            }
        }

        return [
            'key' => 'laba-rugi-bulanan',
            'title' => 'Laba Rugi Bulanan ' . $result['year'],
            'subtitle' => 'Tahun ' . $result['year'] . ' · ' . $this->storeLabel() . ' · dalam Rupiah',
            'headings' => array_merge(['Kode', 'Akun'], self::MONTHS, ['Total']),
            'rows' => $rows,
            'right' => range(2, 14),
            'money' => false,
            'compact' => true,
            'note' => 'Dari jurnal yang sudah diposting. Tiap bulan dihitung seperti Laporan Laba Rugi untuk bulan itu.',
        ];
    }

    protected function getHeaderActions(): array
    {
        return $this->exportActions();
    }
}
