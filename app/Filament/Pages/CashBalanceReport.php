<?php

namespace App\Filament\Pages;

use App\Models\ChartOfAccount;
use App\Models\PaymentAccount;
use App\Services\FinancialStatementService;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * "Saldo Kas & Bank" (Keuangan Fase 1, 2026-11-05): saldo semua akun kas/bank hari ini dari jurnal posted, beserta rekening dan toko yang
 * terhubung. Akun kas yang belum terhubung ke rekening (mis. 1101 lama) tetap tampil supaya total kas lengkap.
 */
class CashBalanceReport extends Page
{
    use \App\Filament\Pages\Concerns\ExportsReportTable;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationGroup = 'Laporan Keuangan';

    protected static ?string $navigationLabel = 'Saldo Kas & Bank';

    protected static ?string $title = 'Saldo Kas & Bank';

    protected static ?int $navigationSort = 302;

    protected static string $view = 'filament.pages.cash-balance-report';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->isFullAccess() ?? false)
            || ($user?->canAccessStaffArea()
                && $user->hasMenuAccess(static::class)
                && $user->hasModuleAction(static::class, 'view', false));
    }

    protected function getHeaderActions(): array
    {
        return $this->exportActions();
    }

    protected function reportExportData(): array
    {
        $rows = $this->getRows();

        return [
            'key' => 'saldo-kas-bank',
            'title' => 'Saldo Kas & Bank',
            'subtitle' => 'Per ' . now()->translatedFormat('d F Y'),
            'headings' => ['Kode', 'Akun', 'Rekening', 'Toko', 'Saldo'],
            'rows' => $rows->map(fn (array $row) => [
                $row['account']->code,
                $row['account']->name,
                $row['payment']?->name ?? 'Belum terhubung ke rekening',
                $row['payment']?->store?->name ?? ($row['payment'] ? 'Pusat' : '—'),
                (float) $row['balance'],
            ])->all(),
            'totals' => ['', 'Total kas dan bank', '', '', (float) $rows->sum('balance')],
            'right' => [4],
            'note' => 'Saldo sampai hari ini dari jurnal yang sudah diposting. Akun kas yang belum punya rekening tetap dihitung.',
        ];
    }

    /** @return Collection<int, array{account: ChartOfAccount, payment: ?PaymentAccount, balance: float}> */
    public function getRows(): Collection
    {
        $statements = app(FinancialStatementService::class);
        $payments = PaymentAccount::with('store')->get()->keyBy('chart_of_account_id');

        return ChartOfAccount::where('is_cash', true)->where('is_postable', true)->orderBy('code')->get()
            ->map(fn (ChartOfAccount $account) => [
                'account' => $account,
                'payment' => $payments->get($account->id),
                'balance' => $statements->balanceAsOf($account, now()),
            ])
            // Akun nonaktif tanpa saldo tidak perlu tampil.
            ->filter(fn (array $row) => $row['account']->is_active || abs($row['balance']) >= 0.005)
            ->values();
    }
}
