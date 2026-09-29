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
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Neraca (Balance Sheet) — Aset = Kewajiban + Modal per tanggal cutoff.
 * TERBATAS full-access; Spv Finance dst. bisa diberi izin baca lewat Hak Akses Detail ('view',
 * default ditolak) dan yang punya toko dikunci ke tokonya (audit Neraca 2026-09-29).
 */
class BalanceSheetReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 300-399 "Laporan Keuangan".
    protected static ?string $navigationGroup = 'Laporan Keuangan';

    protected static ?string $navigationLabel = 'Neraca';

    protected static ?string $title = 'Neraca (Balance Sheet)';

    protected static ?int $navigationSort = 304;

    protected static string $view = 'filament.pages.balance-sheet-report';

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
            'as_of' => now()->toDateString(),
            'store_id' => $this->isRestricted() ? auth()->user()?->store_id : null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('preset')
                    ->label('Tanggal Cepat')
                    ->options([
                        'today' => 'Hari ini',
                        'last_month_end' => 'Akhir bulan lalu',
                        'last_quarter_end' => 'Akhir kuartal lalu',
                        'last_year_end' => 'Akhir tahun lalu',
                    ])
                    ->placeholder('Pilih untuk mengisi tanggal otomatis')
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set) {
                        $date = match ($state) {
                            'today' => now(),
                            'last_month_end' => now()->subMonthNoOverflow()->endOfMonth(),
                            'last_quarter_end' => now()->subQuarter()->endOfQuarter(),
                            'last_year_end' => now()->subYear()->endOfYear(),
                            default => null,
                        };

                        if ($date) {
                            $set('as_of', $date->toDateString());
                        }
                    }),

                DatePicker::make('as_of')
                    ->label('Per Tanggal')
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
                    ->options(['prev_month' => 'Sebulan sebelumnya', 'prev_year' => 'Tahun lalu (tanggal yang sama)'])
                    ->placeholder('Tanpa pembanding')
                    ->live(),
            ])
            ->statePath('data')
            ->columns(3);
    }

    /** "Per Tanggal" dikosongkan -> kembali ke hari ini DENGAN pemberitahuan (bukan diam-diam). */
    public function updatedData(): void
    {
        if (empty($this->data['as_of'])) {
            $this->data['as_of'] = now()->toDateString();

            Notification::make()
                ->title('"Per Tanggal" wajib diisi')
                ->body('Diset ke hari ini.')
                ->warning()
                ->send();
        }
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
                ->withProperties(['report' => 'balance_sheet', 'format' => $format, 'as_of' => $this->data['as_of'] ?? null, 'store_id' => $this->storeId()])
                ->log('Ekspor Neraca (' . $format . ')');
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
                        new BalanceSheetExport($this->getResult()),
                        'neraca-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

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
        $storeId = $this->storeId();

        $service = app(FinancialStatementService::class);
        $result = $service->balanceSheet($asOf, $storeId);
        $result['store_label'] = $this->storeLabel();

        $mode = $this->data['compare'] ?? null;
        $prevAsOf = match ($mode) {
            'prev_month' => $asOf->copy()->subMonthNoOverflow(),
            'prev_year' => $asOf->copy()->subYear(),
            default => null,
        };
        $result['compare'] = $prevAsOf ? $service->balanceSheet($prevAsOf, $storeId) : null;
        $result['compare_label'] = $prevAsOf?->format('d M Y');

        // Akun yang HANYA punya saldo di tanggal pembanding tetap ditampilkan (saldo sekarang 0), supaya
        // daftar akun menjumlah ke total pembanding. Total saat ini tidak berubah (nilainya 0).
        if ($result['compare']) {
            foreach (['aset', 'kewajiban', 'modal'] as $group) {
                $existing = $result[$group]['rows']->pluck('account.id')->all();

                $missing = $result['compare'][$group]['rows']
                    ->reject(fn ($r) => in_array($r['account']->id, $existing, true))
                    ->map(fn ($r) => ['account' => $r['account'], 'debit' => 0.0, 'credit' => 0.0, 'balance' => 0.0, 'compare_only' => true]);

                if ($missing->isNotEmpty()) {
                    $result[$group]['rows'] = $result[$group]['rows']->concat($missing)
                        ->sortBy(fn ($r) => $r['account']->code)
                        ->values();
                }
            }
        }

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
            'store_id' => $this->storeId(),
        ]);
    }

    public function getNotices(): array
    {
        $asOf = Carbon::parse($this->data['as_of'] ?? now()->toDateString());

        // Neraca kumulatif: draft sejak awal; daftar periode tertutup tidak relevan (semua periode lampau).
        return app(FinancialStatementService::class)->reportNotices(Carbon::create(1970, 1, 1), $asOf, $this->storeId(), false, true);
    }
}
