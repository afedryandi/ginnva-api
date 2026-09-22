<?php

namespace App\Filament\Pages;

use App\Models\ChartOfAccount;
use App\Models\FinanceDashboardWidget;
use App\Models\FinanceTransaction;
use App\Models\Store;
use App\Services\FinancialStatementService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * "Laporan Keuangan" — ringkasan bulanan (total Pemasukan/Pengeluaran/
 * Saldo Bersih + rincian per kategori), BUKAN resource biasa karena
 * tidak ada 1 model yang dilist — murni agregasi FinanceTransaction
 * lewat 1 bulan berjalan. SAMA POLA dengan InventoryDashboard/
 * SendNotification (Page biasa, view sendiri) — form month/store
 * SEMUANYA ->live(), jadi cukup ganti pilihan tanpa tombol "Terapkan"
 * terpisah (Livewire re-render otomatis).
 */
class FinanceReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationLabel = 'Laporan Keuangan';

    protected static ?string $title = 'Laporan Keuangan';

    // Direnumber 7 (dari 3) -- audit navigasi 2026-09-15, tabrakan
    // dengan TransactionApprovalRequestResource yang sama-sama sort=3.
    protected static ?int $navigationSort = 7;

    protected static string $view = 'filament.pages.finance-report';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public function mount(): void
    {
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        $this->form->fill([
            'month' => now()->startOfMonth()->toDateString(),
            'store_id' => $isFullAccess ? null : auth()->user()?->store_id,
        ]);
    }

    public function form(Form $form): Form
    {
        $isFullAccess = auth()->user()?->isFullAccess() ?? false;

        return $form
            ->schema([
                DatePicker::make('month')
                    ->label('Bulan')
                    ->native(false)
                    ->displayFormat('F Y')
                    ->closeOnDateSelection()
                    ->required()
                    ->live(),

                Select::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id'))
                    ->placeholder('Semua Toko')
                    ->searchable()
                    ->visible($isFullAccess)
                    ->live(),
            ])
            ->statePath('data')
            ->columns(2);
    }

    private function selectedMonth(): Carbon
    {
        $value = $this->data['month'] ?? now()->toDateString();

        return Carbon::parse($value)->startOfMonth();
    }

    /**
     * non-full-access SELALU dipaksa ke tokonya sendiri di sini — field
     * 'store_id' disembunyikan total dari form untuk mereka (bukan cuma
     * disabled), jadi $this->data['store_id'] tidak pernah terisi kalau
     * tidak dipaksa balik di sini.
     */
    private function selectedStoreId(): ?int
    {
        $user = auth()->user();
        if (! ($user?->isFullAccess() ?? false)) {
            return $user?->store_id;
        }

        return $this->data['store_id'] ?? null;
    }

    public function getTotals(): array
    {
        return FinanceTransaction::totalsForMonth($this->selectedMonth(), $this->selectedStoreId());
    }

    public function getBreakdown(): \Illuminate\Support\Collection
    {
        return FinanceTransaction::byCategoryForMonth($this->selectedMonth(), $this->selectedStoreId());
    }

    /**
     * "Tambah Widget" (audit Majoo f46) — TERBATAS full-access, sama
     * filosofi ChartOfAccountResource/JournalEntryResource: saldo akun
     * COA individual (mis. rekening bank spesifik) adalah data kontrol
     * finansial perusahaan, bukan operasional harian toko.
     */
    protected function getHeaderActions(): array
    {
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            return [];
        }

        return [
            Action::make('manageWidgets')
                ->label('Kelola Widget')
                ->icon('heroicon-o-squares-plus')
                ->color('gray')
                ->form([
                    Select::make('chart_of_account_ids')
                        ->label('Akun COA yang Dipin')
                        ->multiple()
                        ->searchable()
                        ->options(fn () => ChartOfAccount::where('is_active', true)->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                        ->default(fn () => auth()->user()->financeDashboardWidgets()->pluck('chart_of_account_id')->all())
                        ->helperText('Muncul sebagai kartu saldo di atas breakdown kategori, dihitung per tanggal akhir bulan yang dipilih.'),
                ])
                ->action(function (array $data) {
                    $userId = auth()->id();
                    FinanceDashboardWidget::where('user_id', $userId)->delete();

                    foreach (array_values($data['chart_of_account_ids'] ?? []) as $i => $accountId) {
                        FinanceDashboardWidget::create([
                            'user_id' => $userId,
                            'chart_of_account_id' => $accountId,
                            'sort_order' => $i,
                        ]);
                    }

                    Notification::make()->title('Widget diperbarui')->success()->send();
                }),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{account: ChartOfAccount, balance: float}>
     */
    public function getPinnedAccountBalances(): \Illuminate\Support\Collection
    {
        if (! (auth()->user()?->isFullAccess() ?? false)) {
            return collect();
        }

        $asOf = $this->selectedMonth()->copy()->endOfMonth();
        $service = app(FinancialStatementService::class);

        return auth()->user()->financeDashboardWidgets()
            ->with('chartOfAccount')
            ->orderBy('sort_order')
            ->get()
            ->filter(fn (FinanceDashboardWidget $w) => $w->chartOfAccount !== null)
            ->map(fn (FinanceDashboardWidget $w) => [
                'account' => $w->chartOfAccount,
                'balance' => $service->balanceAsOf($w->chartOfAccount, $asOf, $this->selectedStoreId()),
            ]);
    }
}
