<?php

namespace App\Filament\Pages;

use App\Exports\CashFlowExport;
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
 * Laporan Arus Kas — metode LANGSUNG (direct method), dikelompokkan
 * Operasional/Investasi/Pendanaan berdasarkan ChartOfAccount::
 * cash_flow_category. Lihat komentar lengkap di
 * FinancialStatementService::cashFlowStatement() soal cara klasifikasi
 * & keterbatasannya. TERBATAS full-access, sama filosofi dengan
 * laporan Keuangan lain yang bersumber dari Jurnal Umum.
 */
class CashFlowReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationLabel = 'Laporan Arus Kas';

    protected static ?string $title = 'Laporan Arus Kas';

    // Direnumber 12 (dari 9) -- audit navigasi 2026-09-15, dampak
    // renumber beruntun akibat tabrakan sort lain di cluster ini.
    protected static ?int $navigationSort = 12;

    protected static string $view = 'filament.pages.cash-flow-report';

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
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->placeholder('Semua Toko')
                    ->searchable()
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    /**
     * "Ekspor Laporan" -- pola sama laporan Penjualan (SalesSummaryReport/
     * VoidReport), Excel & PDF dibangun dari getResult() yang sama persis
     * dipakai halaman web. Landscape (bukan portrait seperti Ringkasan
     * Penjualan) karena tiap section bisa punya banyak baris transaksi
     * per kategori arus kas.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export ke Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => Excel::download(
                    new CashFlowExport($this->getResult()),
                    'laporan-arus-kas-' . now()->format('Ymd-His') . '.xlsx'
                )),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.cash_flow_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'laporan-arus-kas-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth()->toDateString());
        $storeId = $this->data['store_id'] ?? null;

        $result = app(FinancialStatementService::class)->cashFlowStatement($from, $to, $storeId);

        // 'from'/'to' ditambahkan di sini (BUKAN dari cashFlowStatement())
        // khusus untuk header periode di file Export -- halaman web sudah
        // punya $this->data['from']/['to'] sendiri lewat form, jadi tidak
        // butuh field ini untuk render, tapi Export/PDF butuh 1 sumber
        // array yang self-contained.
        $result['from'] = $from;
        $result['to'] = $to;

        return $result;
    }
}
