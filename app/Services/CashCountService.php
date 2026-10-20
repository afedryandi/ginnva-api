<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\CashCount;
use App\Models\PaymentAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hitung kas harian (Keuangan Fase 4, 2026-11-07). record() mencatat uang yang dihitung dan saldo sistem pada tanggal itu; selisih tidak
 * dijurnalkan otomatis. adjust() menjurnalkan selisih setelah dikonfirmasi: kekurangan Debit 7900 Beban Non-Operasional Lain / Kredit kas,
 * kelebihan Debit kas / Kredit 7200 Pendapatan Non-Operasional Lain.
 */
class CashCountService
{
    public function __construct(private readonly FinancialStatementService $statements)
    {
    }

    public function record(PaymentAccount $account, Carbon $date, float $counted, ?string $notes, ?int $userId): CashCount
    {
        if (! in_array($account->kind, ['kas', 'kas_kecil'], true)) {
            throw new RuntimeException('Hitung kas hanya untuk rekening kas dan kas kecil, bukan rekening bank.');
        }

        if ($counted < 0) {
            throw new RuntimeException('Nominal hasil hitung tidak boleh negatif.');
        }

        if ($date->toDateString() > now()->toDateString()) {
            throw new RuntimeException('Tanggal hitung tidak boleh di masa depan.');
        }

        if (CashCount::withoutGlobalScopes()->where('payment_account_id', $account->id)->whereDate('count_date', $date->toDateString())->exists()) {
            throw new RuntimeException('Kas ini sudah dihitung pada tanggal tersebut.');
        }

        $coa = $account->chartAccount;

        if (! $coa) {
            throw new RuntimeException('Rekening ini belum terhubung ke akun di Bagan Akun.');
        }

        $system = round($this->statements->balanceAsOf($coa, $date, null), 2);
        $counted = round($counted, 2);
        $difference = round($counted - $system, 2);

        if (abs($difference) >= 0.005 && blank($notes)) {
            throw new RuntimeException('Ada selisih Rp' . number_format(abs($difference), 0, ',', '.') . ' antara hitungan dan saldo sistem; tuliskan penjelasannya di catatan.');
        }

        return CashCount::create([
            'payment_account_id' => $account->id,
            'store_id' => $account->store_id,
            'count_date' => $date->toDateString(),
            'counted_amount' => $counted,
            'system_balance' => $system,
            'difference' => $difference,
            'notes' => $notes,
            'counted_by' => $userId,
        ]);
    }

    public function adjust(CashCount $count, ?int $userId): CashCount
    {
        return DB::transaction(function () use ($count, $userId) {
            $locked = CashCount::withoutGlobalScopes()->whereKey($count->id)->lockForUpdate()->firstOrFail();

            if ($locked->isAdjusted()) {
                throw new RuntimeException('Selisih hitungan ini sudah dijurnalkan.');
            }

            if (! $locked->hasDifference()) {
                throw new RuntimeException('Tidak ada selisih yang perlu dijurnalkan.');
            }

            $account = PaymentAccount::with('chartAccount')->findOrFail($locked->payment_account_id);
            $cash = $account->chartAccount;
            $diff = (float) $locked->difference;
            $counterCode = $diff < 0 ? '7900' : '7200';
            $counter = ChartOfAccount::where('code', $counterCode)->first();

            if (! $cash || ! $counter) {
                throw new RuntimeException("Akun kas atau akun {$counterCode} tidak ditemukan di Bagan Akun.");
            }

            $amount = abs($diff);
            $service = app(JournalEntryService::class);
            $date = Carbon::parse($locked->count_date);
            $entryDate = \App\Models\AccountingPeriod::isClosedFor($date) ? now()->toDateString() : $date->toDateString();

            $entry = $service->create([
                'entry_date' => $entryDate,
                'store_id' => $locked->store_id,
                'description' => ($diff < 0 ? 'Kekurangan' : 'Kelebihan') . " kas {$account->name} hasil hitung " . $date->format('d M Y'),
                'reference_type' => 'cash_count',
                'reference_id' => $locked->id,
                'created_by' => $userId,
            ], $diff < 0
                ? [['chart_of_account_id' => $counter->id, 'debit' => $amount], ['chart_of_account_id' => $cash->id, 'credit' => $amount]]
                : [['chart_of_account_id' => $cash->id, 'debit' => $amount], ['chart_of_account_id' => $counter->id, 'credit' => $amount]]);
            $service->post($entry, $userId);

            $locked->update(['adjustment_journal_entry_id' => $entry->id, 'adjusted_by' => $userId, 'adjusted_at' => now()]);

            return $locked->fresh();
        });
    }
}
