<?php

namespace App\Filament\Pages;

use App\Exports\GeneralLedgerExport;
use App\Models\ChartOfAccount;
use App\Models\Store;
use App\Services\FinancialStatementService;
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
use Maatwebsite\Excel\Facades\Excel;

/**
 * Buku Besar — rincian TIAP baris jurnal yang menyentuh 1 akun terpilih
 * dalam 1 rentang tanggal, dengan saldo berjalan per baris. Pelengkap
 * Neraca Saldo yang cuma kasih 1 angka saldo akhir per akun — ini yang
 * dipakai untuk "ngecek kenapa saldo akun ini segini". TERBATAS
 * full-access, sama filosofi dengan TrialBalanceReport/IncomeStatementReport.
 */
class GeneralLedgerReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationLabel = 'Buku Besar';

    protected static ?string $title = 'Buku Besar';

    // Direnumber 10 (dari 7) -- audit navigasi 2026-09-15, dampak
    // renumber beruntun akibat tabrakan sort lain di cluster ini.
    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.general-ledger-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'chart_of_account_id' => ChartOfAccount::where('is_postable', true)->orderBy('code')->value('id'),
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'store_id' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('chart_of_account_id')
                    ->label('Akun')
                    ->options(fn () => ChartOfAccount::where('is_postable', true)
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                    ->searchable()
                    ->required()
                    ->live()
                    ->columnSpanFull(),

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
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->placeholder('Semua Toko')
                    ->searchable()
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    /**
     * "Ekspor Laporan" -- pola sama laporan Penjualan/Keuangan lain.
     * getResult() bisa null kalau belum pilih akun (lihat form()) --
     * di-guard dengan notifikasi gagal, BUKAN dibiarkan lempar error ke
     * Excel::download()/Pdf::loadView() dengan null.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    if (! $result) {
                        Notification::make()->title('Pilih akun dulu untuk export.')->danger()->send();

                        return;
                    }

                    return Excel::download(
                        new GeneralLedgerExport($result),
                        'buku-besar-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    if (! $result) {
                        Notification::make()->title('Pilih akun dulu untuk export.')->danger()->send();

                        return;
                    }

                    $pdf = Pdf::loadView('pdf.general_ledger_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'buku-besar-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): ?array
    {
        $accountId = $this->data['chart_of_account_id'] ?? null;
        if (! $accountId) {
            return null;
        }

        $account = ChartOfAccount::find($accountId);
        if (! $account) {
            return null;
        }

        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth()->toDateString());
        $storeId = $this->data['store_id'] ?? null;

        $result = app(FinancialStatementService::class)->generalLedger($account, $from, $to, $storeId);

        // 'from'/'to' ditambahkan di sini untuk header periode di file
        // Export/PDF (sama pola dengan CashFlowReport) -- generalLedger()
        // sendiri tidak butuh tahu rentang tanggal sebagai output, cuma
        // sebagai filter query.
        $result['from'] = $from;
        $result['to'] = $to;

        return $result;
    }
}
