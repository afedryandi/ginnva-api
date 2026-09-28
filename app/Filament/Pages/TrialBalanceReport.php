<?php

namespace App\Filament\Pages;

use App\Exports\TrialBalanceExport;
use App\Models\Store;
use App\Services\FinancialStatementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Neraca Saldo — saldo tiap akun per tanggal cutoff (atau Saldo Awal | Mutasi | Saldo Akhir kalau
 * "Dari Tanggal" diisi), dihitung dari Jurnal Umum yang 'posted'. Akun Laba Rugi dihitung sejak
 * 1 Januari tahun laporan (lihat FinancialStatementService::trialBalance $resetProfitLoss).
 *
 * TERBATAS full-access; Spv Finance dst. bisa diberi izin baca lewat Hak Akses Detail ('view',
 * default ditolak) -- dan non-full-access yang punya toko otomatis dibatasi ke tokonya.
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
            'hide_zero' => false,
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
                    ->options(fn () => [FinancialStatementService::COMPANY_WIDE => 'Pusat / Tanpa Toko'] + Store::pluck('name', 'id')->all())
                    ->helperText('Memilih toko TIDAK mencakup jurnal pusat (tanpa toko, mis. gaji pusat/penyusutan) — pilih "Pusat / Tanpa Toko" untuk melihatnya, atau kosongkan untuk semua.')
                    ->placeholder('Semua Toko')
                    ->searchable()
                    ->live()
                    // Non-full-access yang punya toko dikunci ke tokonya sendiri.
                    ->disabled(fn () => $this->isRestricted() && auth()->user()?->store_id !== null),

                TextInput::make('search')
                    ->label('Cari Akun')
                    ->placeholder('kode atau nama akun')
                    ->live(debounce: 400)
                    ->helperText('Hanya memfilter tampilan; total dan ekspor tetap seluruh akun.'),

                Toggle::make('hide_zero')
                    ->label('Sembunyikan akun bersaldo nol')
                    ->live()
                    ->inline(false),
            ])
            ->statePath('data')
            ->columns(3);
    }

    /** Dari Tanggal tidak boleh setelah Per Tanggal: dikosongkan + diberi tahu (bukan diabaikan diam-diam). */
    public function updatedData(): void
    {
        $from = $this->data['from'] ?? null;
        $asOf = $this->data['as_of'] ?? null;

        if ($from && $asOf && Carbon::parse($from)->gt(Carbon::parse($asOf))) {
            $this->data['from'] = null;

            Notification::make()
                ->title('"Dari Tanggal" tidak boleh setelah "Per Tanggal"')
                ->body('Kolom "Dari Tanggal" dikosongkan.')
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

        return $this->data['store_id'] ?? null;
    }

    private function logExport(string $format): void
    {
        try {
            activity('report_export')
                ->causedBy(auth()->user())
                ->withProperties(['report' => 'trial_balance', 'format' => $format, 'as_of' => $this->data['as_of'] ?? null, 'from' => $this->data['from'] ?? null, 'store_id' => $this->storeId()])
                ->log('Ekspor Neraca Saldo (' . $format . ')');
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
                        new TrialBalanceExport($this->getResult()),
                        'neraca-saldo-' . now()->format('Ymd-His') . '.xlsx'
                    );
                }),

            Action::make('exportPdf')
                ->label('Export ke PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $this->logExport('pdf');

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
        $from = ! empty($this->data['from']) ? Carbon::parse($this->data['from']) : null;

        return app(FinancialStatementService::class)->trialBalance($asOf, $this->storeId(), $from && $from->lte($asOf) ? $from : null, true);
    }

    /**
     * Baris untuk TAMPILAN: difilter pencarian/saldo nol lalu dikelompokkan per tipe akun (Aset, Kewajiban, ...)
     * berurutan menurut standar. Total & ekspor tetap memakai seluruh akun (getResult()).
     *
     * @return Collection<string, Collection> tipe => baris
     */
    public function getGroupedRows(array $result): Collection
    {
        $search = mb_strtolower(trim((string) ($this->data['search'] ?? '')));
        $hideZero = (bool) ($this->data['hide_zero'] ?? false);

        $rows = $result['rows']->filter(function (array $row) use ($search, $hideZero) {
            if ($hideZero && abs($row['balance']) < 0.005 && abs($row['debit']) < 0.005 && abs($row['credit']) < 0.005) {
                return false;
            }

            return $search === ''
                || str_contains(mb_strtolower($row['account']->code . ' ' . $row['account']->name), $search);
        });

        $order = array_keys(FinancialStatementService::TYPE_LABELS);

        return $rows->groupBy(fn (array $row) => $row['account']->type)
            ->sortBy(fn ($group, $type) => ($pos = array_search($type, $order, true)) === false ? 99 : $pos);
    }

    /** Link drill-down ke Buku Besar untuk 1 akun (rentang = periode laporan, atau awal tahun s.d. Per Tanggal). */
    public function ledgerUrl(int $accountId): string
    {
        $asOf = Carbon::parse($this->data['as_of'] ?? now()->toDateString());
        $from = ! empty($this->data['from']) ? Carbon::parse($this->data['from']) : $asOf->copy()->startOfYear();

        return GeneralLedgerReport::getUrl([
            'chart_of_account_id' => $accountId,
            'from' => $from->toDateString(),
            'to' => $asOf->toDateString(),
            'store_id' => $this->storeId(),
        ]);
    }

    public function getNotices(): array
    {
        $asOf = Carbon::parse($this->data['as_of'] ?? now()->toDateString());

        $notices = app(FinancialStatementService::class)->reportNotices(Carbon::create(1970, 1, 1), $asOf, $this->storeId(), false, true);

        if ($asOf->gt(today())) {
            array_unshift($notices, ['type' => 'info', 'text' => 'Per Tanggal melewati hari ini — jurnal bertanggal masa depan (kalau ada) ikut dihitung.']);
        }

        return $notices;
    }
}
