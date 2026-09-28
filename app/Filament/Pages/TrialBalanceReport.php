<?php

namespace App\Filament\Pages;

use App\Exports\TrialBalanceExport;
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
 * Neraca Saldo — saldo KUMULATIF tiap akun per tanggal cutoff (bukan 1
 * periode seperti Laporan Laba Rugi), dihitung dari Jurnal Umum yang
 * 'posted'. TERBATAS full-access, sama filosofi dengan JournalEntryResource
 * — sumber datanya (Jurnal Umum) memang full-access-only.
 */
class TrialBalanceReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationLabel = 'Neraca Saldo';

    protected static ?string $title = 'Neraca Saldo';

    // Direnumber 8 (dari 5) -- audit navigasi 2026-09-15, tabrakan
    // dengan PayableResource.
    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.pages.trial-balance-report';

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
                DatePicker::make('from')
                    ->label('Dari Tanggal (opsional)')
                    ->native(false)
                    ->live()
                    ->helperText('Diisi = tampil Saldo Awal, Mutasi Debit/Kredit periode, dan Saldo Akhir per akun.'),

                DatePicker::make('as_of')
                    ->label('Per Tanggal')
                    ->native(false)
                    ->required()
                    ->live()
                    ->afterOrEqual('from'),

                Select::make('store_id')
                    ->label('Toko')
                    ->options(fn () => [\App\Services\FinancialStatementService::COMPANY_WIDE => 'Pusat / Tanpa Toko'] + Store::pluck('name', 'id')->all())
                    ->helperText('Memilih toko TIDAK mencakup jurnal pusat (tanpa toko, mis. gaji pusat/penyusutan) — pilih "Pusat / Tanpa Toko" untuk melihatnya, atau kosongkan untuk semua.')
                    ->placeholder('Semua Toko')
                    ->searchable()
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
                    new TrialBalanceExport($this->getResult()),
                    'neraca-saldo-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.trial_balance_report', ['result' => $result])->setPaper('a4', 'portrait');
                    $filename = 'neraca-saldo-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $asOf = Carbon::parse($this->data['as_of'] ?? now()->toDateString());
        $storeId = $this->data['store_id'] ?? null;

        $from = ! empty($this->data['from']) ? Carbon::parse($this->data['from']) : null;

        return app(FinancialStatementService::class)->trialBalance($asOf, $storeId, $from && $from->lte($asOf) ? $from : null);
    }

    public function getNotices(): array
    {
        $asOf = Carbon::parse($this->data['as_of'] ?? now()->toDateString());

        return app(FinancialStatementService::class)->reportNotices(Carbon::create(1970, 1, 1), $asOf, $this->data['store_id'] ?? null, false, true);
    }
}
