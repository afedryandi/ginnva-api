<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Anggaran vs realisasi per bulan (Keuangan Fase 4, 2026-11-07). Realisasi = mutasi akun pada bulan itu dari jurnal posted (beban: debit
 * dikurangi kredit), untuk seluruh perusahaan atau satu toko. Akun beban yang punya realisasi tetapi tidak punya anggaran ikut tampil
 * ditandai "tanpa anggaran". Status: lewat (> 100%), hampir (>= 80%), aman.
 */
class BudgetService
{
    public const WARN_AT = 0.8;

    /**
     * @return Collection<int, array{account: ChartOfAccount, budget: float, actual: float, remaining: float, percent: ?float, status: string}>
     */
    public function compare(Carbon $month, ?int $storeId = null): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $budgets = Budget::withoutGlobalScopes()
            ->where('period_month', $start->toDateString())
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId), fn ($q) => $q->whereNull('store_id'))
            ->get()
            ->keyBy('chart_of_account_id');

        $actuals = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_entry_lines.chart_of_account_id')
            ->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('chart_of_accounts.type', ['beban_pokok', 'beban_operasional', 'beban_lain'])
            ->when($storeId, fn ($q) => $q->where('journal_entries.store_id', $storeId))
            ->groupBy('journal_entry_lines.chart_of_account_id')
            ->selectRaw('journal_entry_lines.chart_of_account_id as account_id, SUM(journal_entry_lines.debit) - SUM(journal_entry_lines.credit) as actual')
            ->pluck('actual', 'account_id')
            ->map(fn ($v) => round((float) $v, 2));

        $accountIds = $budgets->keys()->merge($actuals->filter(fn ($v) => abs($v) >= 0.005)->keys())->unique();
        $accounts = ChartOfAccount::whereIn('id', $accountIds)->get()->keyBy('id');

        return $accountIds
            ->map(function ($id) use ($budgets, $actuals, $accounts) {
                $budget = round((float) ($budgets[$id]->amount ?? 0), 2);
                $actual = (float) ($actuals[$id] ?? 0);
                $percent = $budget > 0 ? round($actual / $budget * 100, 1) : null;

                $status = match (true) {
                    $budget <= 0 && $actual > 0 => 'tanpa_anggaran',
                    $budget > 0 && $actual > $budget => 'lewat',
                    $budget > 0 && $actual >= $budget * self::WARN_AT => 'hampir',
                    default => 'aman',
                };

                return [
                    'account' => $accounts[$id],
                    'budget' => $budget,
                    'actual' => $actual,
                    'remaining' => round($budget - $actual, 2),
                    'percent' => $percent,
                    'status' => $status,
                ];
            })
            ->filter(fn (array $row) => isset($row['account']))
            ->sortBy(fn (array $row) => $row['account']->code)
            ->values();
    }

    /** Salin anggaran bulan sebelumnya ke bulan ini untuk akun yang belum punya anggaran; mengembalikan jumlah baris yang disalin. */
    public function copyFromPrevious(Carbon $month, ?int $storeId, ?int $userId): int
    {
        $target = $month->copy()->startOfMonth();
        $source = $target->copy()->subMonth();
        $copied = 0;

        $rows = Budget::withoutGlobalScopes()->where('period_month', $source->toDateString())
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId), fn ($q) => $q->whereNull('store_id'))->get();

        foreach ($rows as $row) {
            $exists = Budget::withoutGlobalScopes()->where('period_month', $target->toDateString())
                ->where('store_id', $row->store_id)->where('chart_of_account_id', $row->chart_of_account_id)->exists();

            if ($exists) {
                continue;
            }

            Budget::create([
                'period_month' => $target->toDateString(), 'store_id' => $row->store_id, 'chart_of_account_id' => $row->chart_of_account_id,
                'amount' => $row->amount, 'notes' => $row->notes, 'created_by' => $userId,
            ]);
            $copied++;
        }

        return $copied;
    }
}
