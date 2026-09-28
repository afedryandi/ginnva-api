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
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Buku Besar — rincian TIAP baris jurnal yang menyentuh 1 akun terpilih
 * dalam 1 rentang tanggal, dengan saldo berjalan per baris. Pelengkap
 * Neraca Saldo yang cuma kasih 1 angka saldo akhir per akun — ini yang
 * dipakai untuk "ngecek kenapa saldo akun ini segini". TERBATAS full-access;
 * Spv Finance dst. bisa diberi izin baca lewat Hak Akses Detail ('view', default ditolak) dan yang
 * punya toko dikunci ke tokonya (audit Buku Besar 2026-09-29).
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
        $query = request()->query();
        $validDate = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;

        $this->form->fill([
            'chart_of_account_id' => (isset($query['chart_of_account_id']) && ChartOfAccount::whereKey((int) $query['chart_of_account_id'])->exists())
                ? (int) $query['chart_of_account_id']
                : ChartOfAccount::where('is_postable', true)->where('is_active', true)->orderBy('code')->value('id'),
            'from' => $validDate($query['from'] ?? null) ?? now()->startOfMonth()->toDateString(),
            'to' => $validDate($query['to'] ?? null) ?? now()->endOfMonth()->toDateString(),
            'store_id' => $this->isRestricted() && auth()->user()?->store_id !== null
                ? auth()->user()->store_id
                : (isset($query['store_id']) && is_numeric($query['store_id']) ? (int) $query['store_id'] : null),
        ]);
    }

    private function accountOptions(): array
    {
        return ChartOfAccount::where('is_postable', true)->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name])
            ->all();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('chart_of_account_id')
                    ->label('Akun')
                    ->options(fn () => $this->accountOptions())
                    ->searchable()
                    ->required()
                    ->live()
                    ->columnSpanFull(),

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

                TextInput::make('search')
                    ->label('Cari di Mutasi')
                    ->placeholder('no. jurnal, keterangan, sumber, pembuat')
                    ->live(debounce: 400)
                    ->helperText('Hanya memfilter tampilan; saldo dan ekspor tetap seluruh mutasi.'),
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
                ->withProperties(['report' => 'general_ledger', 'format' => $format, 'account_id' => $this->data['chart_of_account_id'] ?? null, 'from' => $this->data['from'] ?? null, 'to' => $this->data['to'] ?? null, 'store_id' => $this->storeId()])
                ->log('Ekspor Buku Besar (' . $format . ')');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Pindah ke akun sebelum/sesudahnya (urut kode) di antara akun aktif yang bisa diposting. */
    private function stepAccount(int $direction): void
    {
        $ids = array_keys($this->accountOptions());
        $current = array_search((int) ($this->data['chart_of_account_id'] ?? 0), $ids, true);

        if ($current === false) {
            return;
        }

        $next = $ids[$current + $direction] ?? null;

        if ($next === null) {
            Notification::make()->title($direction < 0 ? 'Ini akun pertama.' : 'Ini akun terakhir.')->warning()->send();

            return;
        }

        $this->data['chart_of_account_id'] = $next;
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
            Action::make('prevAccount')
                ->label('Akun Sebelumnya')
                ->icon('heroicon-o-chevron-left')
                ->color('gray')
                ->action(fn () => $this->stepAccount(-1)),

            Action::make('nextAccount')
                ->label('Akun Berikutnya')
                ->icon('heroicon-o-chevron-right')
                ->iconPosition('after')
                ->color('gray')
                ->action(fn () => $this->stepAccount(1)),

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

                    $this->logExport('xlsx');

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

                    $this->logExport('pdf');

                    $pdf = Pdf::loadView('pdf.general_ledger_report', ['result' => $result])->setPaper('a4', 'landscape');
                    $filename = 'buku-besar-' . now()->format('Ymd-His') . '.pdf';

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

    /** Baris mutasi untuk TAMPILAN, difilter kotak pencarian (saldo/total/ekspor tetap seluruh baris). */
    public function getDisplayRows(array $result): Collection
    {
        $search = mb_strtolower(trim((string) ($this->data['search'] ?? '')));

        if ($search === '') {
            return $result['rows'];
        }

        return $result['rows']->filter(fn (array $row) => str_contains(
            mb_strtolower($row['entry_number'] . ' ' . $row['description'] . ' ' . ($row['source'] ?? '') . ' ' . ($row['creator'] ?? '')),
            $search
        ))->values();
    }

    public function getNotices(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth()->toDateString());
        $to = Carbon::parse($this->data['to'] ?? now()->endOfMonth()->toDateString());

        return app(FinancialStatementService::class)->reportNotices($from, $to, $this->storeId());
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

        $result = app(FinancialStatementService::class)->generalLedger($account, $from, $to, $this->storeId());

        // 'from'/'to'/'store_label' ditambahkan di sini untuk header di halaman/Export/PDF --
        // generalLedger() sendiri cuma memakai rentang tanggal & toko sebagai filter query.
        $result['from'] = $from;
        $result['to'] = $to;
        $result['store_label'] = $this->storeLabel();

        return $result;
    }
}
