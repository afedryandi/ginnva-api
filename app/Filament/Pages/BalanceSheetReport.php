<?php

namespace App\Filament\Pages;

use App\Exports\BalanceSheetExport;
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
 * Neraca (Balance Sheet) — Aset = Kewajiban + Modal per tanggal cutoff.
 * TERBATAS full-access, sama filosofi dengan laporan Keuangan lain yang
 * bersumber dari Jurnal Umum (Neraca Saldo, Laba Rugi, Buku Besar).
 */
class BalanceSheetReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationLabel = 'Neraca';

    protected static ?string $title = 'Neraca (Balance Sheet)';

    // Direnumber 11 (dari 8) -- audit navigasi 2026-09-15, dampak
    // renumber beruntun akibat tabrakan sort lain di cluster ini.
    protected static ?int $navigationSort = 11;

    protected static string $view = 'filament.pages.balance-sheet-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'as_of' => now()->toDateString(),
            'store_id' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('as_of')
                    ->label('Per Tanggal')
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
                    ->options(['prev_month' => 'Sebulan sebelumnya', 'prev_year' => 'Tahun lalu (tanggal yang sama)'])
                    ->placeholder('Tanpa pembanding')
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new BalanceSheetExport($this->getResult()),
                    'neraca-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.balance_sheet_report', ['result' => $result])->setPaper('a4', 'portrait');
                    $filename = 'neraca-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $asOf = Carbon::parse($this->data['as_of'] ?? now()->toDateString());
        $storeId = $this->data['store_id'] ?? null;

        $service = app(FinancialStatementService::class);
        $result = $service->balanceSheet($asOf, $storeId);

        $mode = $this->data['compare'] ?? null;
        $prevAsOf = match ($mode) {
            'prev_month' => $asOf->copy()->subMonthNoOverflow(),
            'prev_year' => $asOf->copy()->subYear(),
            default => null,
        };
        $result['compare'] = $prevAsOf ? $service->balanceSheet($prevAsOf, $storeId) : null;
        $result['compare_label'] = $prevAsOf?->format('d M Y');

        return $result;
    }

    /** Link drill-down ke Buku Besar (dari awal tahun berjalan sampai tanggal neraca). */
    public function ledgerUrl(int $accountId): string
    {
        $asOf = Carbon::parse($this->data['as_of'] ?? now()->toDateString());

        return GeneralLedgerReport::getUrl([
            'chart_of_account_id' => $accountId,
            'from' => $asOf->copy()->startOfYear()->toDateString(),
            'to' => $asOf->toDateString(),
            'store_id' => $this->data['store_id'] ?? null,
        ]);
    }

    public function getNotices(): array
    {
        $asOf = Carbon::parse($this->data['as_of'] ?? now()->toDateString());

        // Neraca kumulatif: draft sejak awal; daftar periode tertutup tidak relevan (semua periode lampau).
        return app(FinancialStatementService::class)->reportNotices(Carbon::create(1970, 1, 1), $asOf, $this->data['store_id'] ?? null, false, true);
    }
}
