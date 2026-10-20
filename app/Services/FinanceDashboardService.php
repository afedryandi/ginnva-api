<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\CashCount;
use App\Models\ChartOfAccount;
use App\Models\FinanceTransactionApprovalRequest;
use App\Models\Payable;
use App\Models\Receivable;
use App\Models\TransactionApprovalRequest;
use App\Models\YearEndClosing;
use Illuminate\Support\Carbon;

/**
 * Ringkasan Dasbor Keuangan (Keuangan Fase 6, 2026-11-09): satu layar kondisi uang dan hal yang perlu ditindak. Murni agregat dari data yang
 * sudah ada; tidak menyimpan apa pun. $storeId null = seluruh perusahaan.
 */
class FinanceDashboardService
{
    /**
     * @return array{
     *   cash_total: float, cash_accounts: list<array{name:string,balance:float}>,
     *   month: array{label:string, revenue:float, net_income:float, prev_revenue:float, prev_net_income:float},
     *   receivable: array{outstanding:float, overdue:float, overdue_count:int},
     *   payable: array{outstanding:float, overdue:float, overdue_count:int},
     *   pending_expense: int, pending_transactions: int, cash_count_differences: int, cash_not_counted_today: int,
     *   budget_over: int, budget_near: int,
     *   previous_month: array{label:string, closed:bool, blockers:int, warnings:int},
     *   year_to_close: ?int
     * }
     */
    public function summary(?int $storeId = null, ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $statements = app(FinancialStatementService::class);

        $cashAccounts = ChartOfAccount::where('is_cash', true)->where('is_postable', true)->orderBy('code')->get()
            ->map(fn (ChartOfAccount $a) => ['name' => $a->name, 'balance' => $statements->balanceAsOf($a, $now, $storeId)])
            ->filter(fn (array $row) => abs($row['balance']) >= 0.005)
            ->values();

        $thisMonth = $statements->incomeStatement($now->copy()->startOfMonth(), $now->copy()->endOfDay(), $storeId);
        $prevStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $prevMonth = $statements->incomeStatement($prevStart, $prevStart->copy()->endOfMonth(), $storeId);

        $budget = app(BudgetService::class)->compare($now, $storeId);

        $previous = $this->previousMonth($prevStart);

        return [
            'cash_total' => round((float) $cashAccounts->sum('balance'), 2),
            'cash_accounts' => $cashAccounts->all(),
            'month' => [
                'label' => $now->translatedFormat('F Y'),
                'revenue' => $thisMonth['sections']['pendapatan']['total'],
                'net_income' => $thisMonth['laba_bersih'],
                'prev_revenue' => $prevMonth['sections']['pendapatan']['total'],
                'prev_net_income' => $prevMonth['laba_bersih'],
            ],
            'receivable' => $this->openAmounts(Receivable::query(), $storeId, $now),
            'payable' => $this->openAmounts(Payable::query(), $storeId, $now),
            'pending_expense' => FinanceTransactionApprovalRequest::whereIn('status', ['pending_manager', 'pending_direksi'])->when($storeId, fn ($q) => $q->where('store_id', $storeId))->count(),
            'pending_transactions' => TransactionApprovalRequest::where('status', 'pending')->count(),
            'cash_count_differences' => CashCount::query()
                ->where('count_date', '>=', $now->copy()->subDays(30)->toDateString())
                ->whereRaw('ABS(difference) >= 0.005')->whereNull('adjusted_at')
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))->count(),
            'cash_not_counted_today' => $this->notCountedToday($storeId, $now),
            'budget_over' => $budget->where('status', 'lewat')->count(),
            'budget_near' => $budget->where('status', 'hampir')->count(),
            'previous_month' => $previous,
            'year_to_close' => $this->yearToClose($now),
        ];
    }

    private function openAmounts($query, ?int $storeId, Carbon $now): array
    {
        $open = $query->whereIn('status', ['unpaid', 'partial'])->when($storeId, fn ($q) => $q->where('store_id', $storeId))->get(['amount', 'amount_paid', 'due_date']);
        $overdue = $open->filter(fn ($r) => $r->due_date !== null && $r->due_date->lt($now->copy()->startOfDay()));

        return [
            'outstanding' => round((float) $open->sum(fn ($r) => (float) $r->amount - (float) $r->amount_paid), 2),
            'overdue' => round((float) $overdue->sum(fn ($r) => (float) $r->amount - (float) $r->amount_paid), 2),
            'overdue_count' => $overdue->count(),
        ];
    }

    private function notCountedToday(?int $storeId, Carbon $now): int
    {
        $accounts = \App\Models\PaymentAccount::query()->where('is_active', true)->whereIn('kind', ['kas', 'kas_kecil'])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))->pluck('id');

        if ($accounts->isEmpty()) {
            return 0;
        }

        $counted = CashCount::whereIn('payment_account_id', $accounts)->whereDate('count_date', $now->toDateString())->distinct()->pluck('payment_account_id');

        return $accounts->diff($counted)->count();
    }

    private function previousMonth(Carbon $start): array
    {
        $items = collect(app(AccountingPeriodService::class)->checklist($start));

        return [
            'label' => $start->translatedFormat('F Y'),
            'closed' => AccountingPeriod::isClosedFor($start),
            'blockers' => $items->where('severity', 'block')->count(),
            'warnings' => $items->where('severity', 'warn')->count(),
        ];
    }

    /** Tahun lewat terakhir yang punya jurnal tetapi belum ditutup (hanya dihitung bila pernah ada penutupan atau tahun itu sudah lewat 31 Jan). */
    private function yearToClose(Carbon $now): ?int
    {
        $year = $now->year - 1;

        if ($now->month < 2 || in_array($year, YearEndClosing::closedYears(), true)) {
            return null;
        }

        return \App\Models\JournalEntry::withoutGlobalScopes()->where('status', 'posted')->whereYear('entry_date', $year)->exists() ? $year : null;
    }
}
