<?php

namespace App\Filament\Pages;

use App\Exports\CashFlowExport;
use App\Models\Store;
use App\Services\FinancialStatementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Laporan Arus Kas — metode LANGSUNG (direct method), dikelompokkan
 * Operasional/Investasi/Pendanaan berdasarkan ChartOfAccount::
 * cash_flow_category. Lihat komentar lengkap di
 * FinancialStatementService::cashFlowStatement() soal cara klasifikasi
 * & keterbatasannya. TERBATAS full-access; Spv Finance dst. bisa diberi izin baca lewat Hak Akses
 * Detail ('view', default ditolak) dan yang punya toko dikunci ke tokonya (audit Arus Kas 2026-09-29).
 */
class CashFlowReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 300-399 "Laporan Keuangan".
    protected static ?string $navigationGroup = 'Laporan Keuangan';

    protected static ?string $navigationLabel = 'Laporan Arus Kas';

    protected static ?string $title = 'Laporan Arus Kas';

    protected static ?int $navigationSort = 305;

    protected static string $view = 'filament.pages.cash-flow-report';

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
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
            'store_id' => $this->isRestricted() ? auth()->user()?->store_id : null,
            'show_details' => false,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
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
                    ->afterStateUpdated(function (?string $state, Set $set) {
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
                        }
                    }),

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
                    ->options(fn () => [FinancialStatementService::COMPANY_WIDE => 'Pusat / Tanpa Toko'] + Store::pluck('name', 'id')->all())
                    ->helperText('Memilih toko TIDAK mencakup jurnal pusat (tanpa toko, mis. gaji pusat/penyusutan) — pilih "Pusat / Tanpa Toko" untuk melihatnya, atau kosongkan untuk semua.')
                    ->placeholder('Semua Toko')
                    ->searchable()
                    ->live()
                    ->disabled(fn () => $this->isRestricted() && auth()->user()?->store_id !== null),

                Select::make('compare')
                    ->label('Bandingkan Dengan')
                    ->options(['prev_period' => 'Periode sebelumnya (durasi sama)', 'prev_year' => 'Periode yang sama tahun lalu'])
                    ->placeholder('Tanpa pembanding')
                    ->live(),

                Toggle::make('show_details')
                    ->label('Tampilkan rincian per jurnal')
                    ->helperText('Mati = ringkas per jenis arus kas.')
                    ->live()
                    ->inline(false),
            ])
            ->statePath('data')
            ->columns(3);
    }

    private function storeId(): ?int
    {
        $user = auth()->user();

        if ($this->isRestricted() && $user?->store_id !== null) {
            return (int) $user->store_id;
        }

        return isset($this->data['store_id']) && $this->data['store_id'] !== '' ? (int) $this->data['store_id'] : null;
    }

    private function storeLabel(): string
    {
        $id = $this->storeId();

        return match (true) {
            $id === null => 'Semua Toko',
            $id === FinancialStatementService::COMPANY_WIDE => 'Pusat / Tanpa Toko',
            default => Store::whereKey($id)->value('name') ?? 'Toko #' . $id,
        };
    }

    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'cash_flow', 'format' => $format, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'store_id' => $this->storeId()])
                ->log('Ekspor Laporan Arus Kas (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
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
                ->action(function () {
                    $this->logExport('xlsx');

                    return Excel::download(
                        new CashFlowExport($this->getResult()),
                        'laporan-arus-kas-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

                    $result = $this->getResult();
                    $pdf = Pdf::loadView('pdf.cash_flow_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'laporan-arus-kas-' . now()->format('Ymd-His') . '.pdf';

                    return response()->streamDownload(fn () => print($pdf->output()), $filename);
                }),
        ];
    }

    /** Tanggal 'Sampai' tidak boleh sebelum 'Dari' (audit Laporan Keuangan 2026-09-29): dikoreksi + diberi tahu. */
    public function updatedData(): void
    {
        $from = $this->data['from'] ?? null;
        $to = $this->data['to'] ?? null;

        if ($from && $to && Carbon::parse($to)->lt(Carbon::parse($from))) {
            $this->data['to'] = $from;

            Notification::make()
                ->title('Tanggal "Sampai" tidak boleh sebelum "Dari"')
                ->body('Diset sama dengan tanggal "Dari".')
                ->warning()
                ->send();
        }
    }

    public function getNotices(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth()->toDateString());

        return app(FinancialStatementService::class)->reportNotices($from, $to, $this->storeId());
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

            $days = (int) $from->diffInDays($to) + 1;
            $prevTo = $from->copy()->subDay();

            return [$prevTo->copy()->subDays($days - 1), $prevTo];
        }

        return [null, null];
    }

    public function getResult(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth()->toDateString());
        $storeId = $this->storeId();

        $service = app(FinancialStatementService::class);
        $result = $service->cashFlowStatement($from, $to, $storeId);

        // 'from'/'to'/'store_label' ditambahkan di sini (BUKAN dari cashFlowStatement())
        // khusus untuk header di file Export/PDF -- 1 sumber array yang self-contained.
        $result['from'] = $from;
        $result['to'] = $to;
        $result['store_label'] = $this->storeLabel();
        $result['show_details'] = (bool) ($this->data['show_details'] ?? false);

        [$prevFrom, $prevTo] = $this->comparisonRange($from, $to);
        $result['compare'] = $prevFrom ? $service->cashFlowStatement($prevFrom, $prevTo, $storeId) : null;
        $result['compare_label'] = $prevFrom ? $prevFrom->format('d M Y') . ' – ' . $prevTo->format('d M Y') : null;

        return $result;
    }
}
