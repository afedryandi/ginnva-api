<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use Illuminate\Support\Carbon;

/**
 * Neraca Saldo & Laporan Laba Rugi — DIHITUNG dari Jurnal Umum (journal_
 * entries + journal_entry_lines) yang statusnya 'posted' SAJA. Jurnal
 * 'draft' TIDAK ikut dihitung — draft berarti belum final/masih bisa
 * berubah, memasukkannya ke laporan resmi akan bikin angka tidak stabil.
 *
 * CATATAN PENTING: laporan ini bersumber dari Jurnal Umum (Fase 2),
 * BUKAN dari Transaksi Keuangan (Fase 1, finance_transactions) — dua
 * sumber data ini belum terhubung (integrasi otomatis Fase 3 belum
 * dibangun). Selama staff masih input Transaksi Keuangan sehari-hari
 * TANPA jurnal manual yang sepadan, laporan di sini akan kosong/tidak
 * mencerminkan transaksi tsb — perlu tetap input Jurnal Umum manual
 * untuk mendapat laporan ini terisi, sampai Fase 3 (auto-posting)
 * dibangun.
 */
class FinancialStatementService
{
    private const INCOME_STATEMENT_TYPES = [
        'pendapatan',
        'beban_pokok',
        'beban_operasional',
        'pendapatan_lain',
        'beban_lain',
        'pajak',
    ];

    /**
     * Saldo tiap akun per tanggal cutoff — kumulatif SEJAK AWAL (semua
     * jurnal posted dengan entry_date <= $asOf), bukan cuma 1 bulan.
     * Neraca Saldo memang begini sifatnya: saldo akhir per titik waktu,
     * beda dari Laba Rugi yang selalu untuk 1 RENTANG periode.
     *
     * @return array{rows: Collection<int, array{account: ChartOfAccount, debit: float, credit: float, balance: float}>, total_debit: float, total_credit: float}
     */
    public function trialBalance(Carbon $asOf, ?int $storeId = null): array
    {
        $sums = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '<=', $asOf->toDateString())
            ->when($storeId, fn ($q) => $q->where('journal_entries.store_id', $storeId))
            ->selectRaw('journal_entry_lines.chart_of_account_id, SUM(journal_entry_lines.debit) as debit, SUM(journal_entry_lines.credit) as credit')
            ->groupBy('journal_entry_lines.chart_of_account_id')
            ->get()
            ->keyBy('chart_of_account_id');

        $accounts = ChartOfAccount::whereIn('id', $sums->keys())
            ->orderBy('code')
            ->get()
            ->keyBy('id');

        $rows = $sums->map(function ($sum) use ($accounts) {
            $account = $accounts[$sum->chart_of_account_id];
            $debit = (float) $sum->debit;
            $credit = (float) $sum->credit;

            // Saldo ditampilkan mengikuti arah saldo normal akunnya —
            // akun debit-normal (Aset/Beban) = debit - kredit, akun
            // kredit-normal (Kewajiban/Modal/Pendapatan) = kredit - debit.
            $balance = $account->isDebitNormal() ? $debit - $credit : $credit - $debit;

            return [
                'account' => $account,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balance,
            ];
        })->sortBy(fn ($row) => $row['account']->code)->values();

        return [
            'rows' => $rows,
            'total_debit' => (float) $rows->sum('debit'),
            'total_credit' => (float) $rows->sum('credit'),
        ];
    }

    /**
     * Laba Rugi untuk 1 RENTANG periode (bukan kumulatif) — cuma akun
     * klasifikasi Pendapatan/HPP/Beban Operasional/Lain-lain/Pajak yang
     * dihitung (Aset/Kewajiban/Modal tidak relevan di laporan ini).
     *
     * @return array{sections: array<string, array{label: string, rows: Collection, total: float}>, laba_kotor: float, laba_operasional: float, laba_sebelum_pajak: float, laba_bersih: float}
     */
    public function incomeStatement(Carbon $from, Carbon $to, ?int $storeId = null): array
    {
        $sums = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_entry_lines.chart_of_account_id')
            ->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('chart_of_accounts.type', self::INCOME_STATEMENT_TYPES)
            ->when($storeId, fn ($q) => $q->where('journal_entries.store_id', $storeId))
            ->selectRaw('journal_entry_lines.chart_of_account_id, SUM(journal_entry_lines.debit) as debit, SUM(journal_entry_lines.credit) as credit')
            ->groupBy('journal_entry_lines.chart_of_account_id')
            ->get()
            ->keyBy('chart_of_account_id');

        $accounts = ChartOfAccount::whereIn('id', $sums->keys())->get()->keyBy('id');

        $labels = [
            'pendapatan' => 'Pendapatan',
            'beban_pokok' => 'Beban Pokok Penjualan (HPP)',
            'beban_operasional' => 'Beban Operasional',
            'pendapatan_lain' => 'Pendapatan Lain-lain',
            'beban_lain' => 'Beban Lain-lain',
            'pajak' => 'Beban Pajak',
        ];

        $sections = [];
        foreach (self::INCOME_STATEMENT_TYPES as $type) {
            $rows = $sums->filter(fn ($s) => $accounts[$s->chart_of_account_id]->type === $type)
                ->map(function ($s) use ($accounts) {
                    $account = $accounts[$s->chart_of_account_id];
                    $debit = (float) $s->debit;
                    $credit = (float) $s->credit;
                    // Pendapatan (kredit-normal) = kredit - debit; HPP/
                    // Beban (debit-normal) = debit - kredit — supaya
                    // jurnal koreksi/retur (baris berlawanan arah) tetap
                    // mengurangi total dengan benar, bukan menambah.
                    $amount = $account->isDebitNormal() ? $debit - $credit : $credit - $debit;

                    return ['account' => $account, 'amount' => $amount];
                })
                ->sortBy(fn ($row) => $row['account']->code)
                ->values();

            $sections[$type] = [
                'label' => $labels[$type],
                'rows' => $rows,
                'total' => (float) $rows->sum('amount'),
            ];
        }

        $labaKotor = $sections['pendapatan']['total'] - $sections['beban_pokok']['total'];
        $labaOperasional = $labaKotor - $sections['beban_operasional']['total'];
        $labaSebelumPajak = $labaOperasional + $sections['pendapatan_lain']['total'] - $sections['beban_lain']['total'];
        $labaBersih = $labaSebelumPajak - $sections['pajak']['total'];

        return [
            'sections' => $sections,
            'laba_kotor' => $labaKotor,
            'laba_operasional' => $labaOperasional,
            'laba_sebelum_pajak' => $labaSebelumPajak,
            'laba_bersih' => $labaBersih,
        ];
    }
}
