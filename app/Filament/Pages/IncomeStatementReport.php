<?php

namespace App\Filament\Pages;

use App\Exports\IncomeStatementExport;
use App\Models\Store;
use App\Services\FinancialStatementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Laporan Laba Rugi — untuk 1 RENTANG periode (beda dari Neraca Saldo
 * yang kumulatif per tanggal cutoff), dihitung dari Jurnal Umum yang
 * 'posted'. TERBATAS full-access, sama filosofi dengan
 * JournalEntryResource/TrialBalanceReport.
 */
class IncomeStatementReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationLabel = 'Laporan Laba Rugi';

    protected static ?string $title = 'Laporan Laba Rugi';

    // Direnumber 9 (dari 6) -- audit navigasi 2026-09-15, tabrakan
    // dengan ReceivableResource.
    protected static ?int $navigationSort = 9;

    protected static string $view = 'filament.pages.income-statement-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'store_id' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('from')
                    ->label('Dari Tanggal')
                    ->native(false)
                    ->required()
                    ->live(),

                DatePicker::make('to')
                    ->label('Sampai Tanggal')
                    ->native(false)
                    ->required()
                    ->live(),

                Select::make('store_id')
                    ->label('Toko')
                    ->options(fn () => [\App\Services\FinancialStatementService::COMPANY_WIDE => 'Pusat / Tanpa Toko'] + Store::pluck('name', 'id')->all())
                    ->helperText('Memilih toko TIDAK mencakup jurnal pusat (tanpa toko, mis. gaji pusat/penyusutan) — pilih "Pusat / Tanpa Toko" untuk melihatnya, atau kosongkan untuk semua.')
                    ->placeholder('Semua Toko')
                    ->searchable()
                    ->live(),

                \Filament\Forms\Components\Select::make('compare')
                    ->label('Bandingkan Dengan')
                    ->options(['prev_period' => 'Periode sebelumnya (durasi sama)', 'prev_year' => 'Periode yang sama tahun lalu'])
                    ->placeholder('Tanpa pembanding')
                    ->live(),
            ])
            ->statePath('data')
            ->columns(4);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new IncomeStatementExport($this->getResult()),
                    'laporan-laba-rugi-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.income_statement_report', ['result' => $result])->setPaper('a4', 'portrait');
                    $filename = 'laporan-laba-rugi-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    /** Tanggal 'Sampai' tidak boleh sebelum 'Dari' (audit Laporan Keuangan 2026-09-29): dikoreksi + diberi tahu. */
    public function updatedData(): void
    {
        $from = $this->data['from'] ?? null;
        $to = $this->data['to'] ?? null;

        if ($from && $to && \Illuminate\Support\Carbon::parse($to)->lt(\Illuminate\Support\Carbon::parse($from))) {
            $this->data['to'] = $from;

            \Filament\Notifications\Notification::make()
                ->title('Tanggal "Sampai" tidak boleh sebelum "Dari"')
                ->body('Diset sama dengan tanggal "Dari".')
                ->warning()
                ->send();
        }
    }


    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth()->toDateString());
        $storeId = $this->data['store_id'] ?? null;

        $service = app(FinancialStatementService::class);
        $result = $service->incomeStatement($from, $to, $storeId);

        [$prevFrom, $prevTo] = $this->comparisonRange($from, $to);
        $result['compare'] = $prevFrom ? $service->incomeStatement($prevFrom, $prevTo, $storeId) : null;
        $result['compare_label'] = $prevFrom ? $prevFrom->format('d M Y') . ' – ' . $prevTo->format('d M Y') : null;

        return $result;
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function comparisonRange(Carbon $from, Carbon $to): array
    {
        $mode = $this->data['compare'] ?? null;

        if ($mode === 'prev_year') {
            return [$from->copy()->subYear(), $to->copy()->subYear()];
        }

        if ($mode === 'prev_period') {
            // Rentang bulan penuh -> mundur sejumlah bulan yang sama; selain itu mundur sejumlah hari yang sama.
            if ($from->isSameDay($from->copy()->startOfMonth()) && $to->isSameDay($to->copy()->endOfMonth())) {
                $months = $from->diffInMonths($to->copy()->addDay()->startOfMonth());
                $prevFrom = $from->copy()->subMonthsNoOverflow($months);

                return [$prevFrom, $prevFrom->copy()->addMonthsNoOverflow($months)->subDay()];
            }

            $days = $from->diffInDays($to) + 1;
            $prevTo = $from->copy()->subDay();

            return [$prevTo->copy()->subDays($days - 1), $prevTo];
        }

        return [null, null];
    }

    /** Link drill-down ke Buku Besar untuk 1 akun di rentang/toko yang sedang dilihat. */
    public function ledgerUrl(int $accountId): string
    {
        return GeneralLedgerReport::getUrl([
            'chart_of_account_id' => $accountId,
            'from' => $this->data['from'] ?? null,
            'to' => $this->data['to'] ?? null,
            'store_id' => $this->data['store_id'] ?? null,
        ]);
    }

    public function getNotices(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth()->toDateString());

        return app(FinancialStatementService::class)->reportNotices($from, $to, $this->data['store_id'] ?? null);
    }
}
