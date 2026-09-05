<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Support\Carbon;

/**
 * Neraca Saldo, Laporan Laba Rugi, Buku Besar, Neraca & Laporan Arus Kas
 * — DIHITUNG dari Jurnal Umum (journal_entries + journal_entry_lines)
 * yang statusnya 'posted' SAJA. Jurnal 'draft' TIDAK ikut dihitung —
 * draft berarti belum final/masih bisa berubah, memasukkannya ke
 * laporan resmi akan bikin angka tidak stabil.
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

    /**
     * Buku Besar — rincian TIAP baris jurnal yang menyentuh 1 akun dalam
     * rentang tanggal, dengan saldo berjalan (running balance) per baris
     * — pelengkap Neraca Saldo yang cuma kasih 1 angka akhir per akun.
     * saldo_awal dihitung dari SEMUA jurnal posted SEBELUM $from (bukan
     * dari nol), supaya saldo berjalan di baris pertama periode tetap
     * nyambung dengan riwayat sebelumnya, bukan seolah-olah akun ini
     * baru mulai dipakai di $from.
     *
     * @return array{account: ChartOfAccount, opening_balance: float, rows: Collection, closing_balance: float, total_debit: float, total_credit: float}
     */
    public function generalLedger(ChartOfAccount $account, Carbon $from, Carbon $to, ?int $storeId = null): array
    {
        $opening = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entry_lines.chart_of_account_id', $account->id)
            ->whereDate('journal_entries.entry_date', '<', $from->toDateString())
            ->when($storeId, fn ($q) => $q->where('journal_entries.store_id', $storeId))
            ->selectRaw('SUM(journal_entry_lines.debit) as debit, SUM(journal_entry_lines.credit) as credit')
            ->first();

        $openingDebit = (float) ($opening->debit ?? 0);
        $openingCredit = (float) ($opening->credit ?? 0);
        $openingBalance = $account->isDebitNormal() ? $openingDebit - $openingCredit : $openingCredit - $openingDebit;

        $lines = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entry_lines.chart_of_account_id', $account->id)
            ->whereBetween('journal_entries.entry_date', [$from->toDateString(), $to->toDateString()])
            ->when($storeId, fn ($q) => $q->where('journal_entries.store_id', $storeId))
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->get([
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
                'journal_entry_lines.description as line_description',
                'journal_entries.entry_number',
                'journal_entries.entry_date',
                'journal_entries.description as entry_description',
            ]);

        $running = $openingBalance;
        $rows = $lines->map(function ($line) use (&$running, $account) {
            $debit = (float) $line->debit;
            $credit = (float) $line->credit;
            $delta = $account->isDebitNormal() ? $debit - $credit : $credit - $debit;
            $running += $delta;

            return [
                'entry_date' => Carbon::parse($line->entry_date),
                'entry_number' => $line->entry_number,
                'description' => $line->line_description ?: $line->entry_description,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $running,
            ];
        });

        return [
            'account' => $account,
            'opening_balance' => $openingBalance,
            'rows' => $rows,
            'closing_balance' => $running,
            'total_debit' => (float) $rows->sum('debit'),
            'total_credit' => (float) $rows->sum('credit'),
        ];
    }

    /**
     * Neraca (Balance Sheet) per tanggal cutoff — Aset = Kewajiban +
     * Modal. Dibangun DI ATAS trialBalance() (kumulatif per akun s.d.
     * $asOf), tinggal dikelompokkan ulang per klasifikasi Aset/
     * Kewajiban/Modal — TIDAK query ulang dari nol.
     *
     * "Laba (Rugi) Tahun Berjalan" DIHITUNG on-the-fly lewat
     * incomeStatement() dari awal tahun kalender s.d. $asOf, BUKAN
     * dibaca dari akun 3900 (akun itu is_postable=false, sengaja tidak
     * pernah diisi jurnal langsung — lihat ChartOfAccountSeeder) —
     * karena belum ada mekanisme "Tutup Periode" yang memindahkan laba
     * tahun berjalan ke Laba Ditahan (3200) secara resmi. Tanpa baris
     * on-the-fly ini, Neraca TIDAK AKAN PERNAH balance selama tahun
     * berjalan (Aset akan selalu lebih besar dari Kewajiban+Modal
     * sebesar laba yang belum "dipindahkan" — atau sebaliknya kalau
     * rugi), padahal itu bukan tanda ada yang salah, cuma karena
     * closing entry belum ada.
     *
     * @return array{as_of: Carbon, aset: array, kewajiban: array, modal: array, total_kewajiban_modal: float, is_balanced: bool}
     */
    public function balanceSheet(Carbon $asOf, ?int $storeId = null): array
    {
        $trial = $this->trialBalance($asOf, $storeId);
        $rows = $trial['rows'];

        $aset = $rows->filter(fn ($r) => $r['account']->type === 'aset')->values();
        $kewajiban = $rows->filter(fn ($r) => $r['account']->type === 'kewajiban')->values();
        $modal = $rows->filter(fn ($r) => $r['account']->type === 'modal')->values();

        $totalAset = (float) $aset->sum('balance');
        $totalKewajiban = (float) $kewajiban->sum('balance');
        $totalModalPosted = (float) $modal->sum('balance');

        $fiscalYearStart = Carbon::create($asOf->year, 1, 1);
        $labaTahunBerjalan = $this->incomeStatement($fiscalYearStart, $asOf, $storeId)['laba_bersih'];

        $totalModal = $totalModalPosted + $labaTahunBerjalan;
        $totalKewajibanModal = $totalKewajiban + $totalModal;

        return [
            'as_of' => $asOf,
            'aset' => ['rows' => $aset, 'total' => $totalAset],
            'kewajiban' => ['rows' => $kewajiban, 'total' => $totalKewajiban],
            'modal' => [
                'rows' => $modal,
                'total_posted' => $totalModalPosted,
                'laba_tahun_berjalan' => $labaTahunBerjalan,
                'total' => $totalModal,
            ],
            'total_kewajiban_modal' => $totalKewajibanModal,
            'is_balanced' => round($totalAset, 2) === round($totalKewajibanModal, 2),
        ];
    }

    /**
     * Laporan Arus Kas — METODE LANGSUNG (direct method), bukan tidak
     * langsung (indirect, yang mulai dari laba bersih lalu koreksi
     * non-kas). Dipilih langsung karena sudah ada jurnal per-transaksi
     * yang eksplisit menyentuh akun kas (ChartOfAccount::is_cash) —
     * tinggal dibaca & diklasifikasi, tidak perlu rekonsiliasi mundur
     * dari laba yang lebih rawan salah untuk sistem sekecil ini.
     *
     * Klasifikasi per JURNAL (bukan per baris) — untuk 1 jurnal yang
     * menyentuh akun kas, kategori Operasional/Investasi/Pendanaan-nya
     * diambil dari akun NON-KAS dengan nominal TERBESAR di jurnal yang
     * sama (ChartOfAccount::cash_flow_category). Ini penyederhanaan
     * SADAR — jurnal yang benar-benar mencampur >1 kategori dalam 1
     * baris (jarang terjadi kalau input jurnal per kejadian, bukan
     * digabung-gabung) akan diklasifikasi ikut yang porsinya terbesar,
     * bukan dipecah proporsional.
     *
     * Jurnal yang SEMUA baris non-kas-nya juga akun kas (transfer antar
     * rekening kas yang sama-sama is_cash, mis. setor tunai ke bank)
     * DILEWATI — pindah uang antar 2 akun yang sama-sama dihitung "kas"
     * di sini tidak mengubah TOTAL kas, jadi tidak relevan ditampilkan.
     *
     * @return array{sections: array<string, array{label: string, rows: Collection, total: float}>, opening_cash: float, net_change: float, closing_cash: float, closing_cash_actual: float, is_reconciled: bool}
     */
    public function cashFlowStatement(Carbon $from, Carbon $to, ?int $storeId = null): array
    {
        $cashAccountIds = ChartOfAccount::where('is_cash', true)->pluck('id');

        $entries = JournalEntry::query()
            ->where('status', 'posted')
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->whereHas('lines', fn ($q) => $q->whereIn('chart_of_account_id', $cashAccountIds))
            ->with('lines.account')
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $labels = [
            'operasional' => 'Arus Kas dari Aktivitas Operasional',
            'investasi' => 'Arus Kas dari Aktivitas Investasi',
            'pendanaan' => 'Arus Kas dari Aktivitas Pendanaan',
        ];

        $buckets = ['operasional' => collect(), 'investasi' => collect(), 'pendanaan' => collect()];

        foreach ($entries as $entry) {
            $cashLines = $entry->lines->filter(fn ($l) => $cashAccountIds->contains($l->chart_of_account_id));
            $cashDelta = (float) $cashLines->sum(fn ($l) => (float) $l->debit - (float) $l->credit);

            if (abs($cashDelta) < 0.01) {
                continue;
            }

            $nonCashLines = $entry->lines->reject(fn ($l) => $cashAccountIds->contains($l->chart_of_account_id));
            if ($nonCashLines->isEmpty()) {
                continue;
            }

            $primary = $nonCashLines->sortByDesc(fn ($l) => max((float) $l->debit, (float) $l->credit))->first();
            $category = $primary->account->cash_flow_category ?? 'operasional';

            $buckets[$category]->push([
                'entry_date' => $entry->entry_date,
                'entry_number' => $entry->entry_number,
                'description' => $entry->description,
                'amount' => $cashDelta,
            ]);
        }

        $sections = [];
        foreach ($buckets as $key => $rows) {
            $sections[$key] = [
                'label' => $labels[$key],
                'rows' => $rows->values(),
                'total' => (float) $rows->sum('amount'),
            ];
        }

        $netChange = array_sum(array_map(fn ($s) => $s['total'], $sections));
        $openingCash = $this->cashBalanceAsOf($from->copy()->subDay(), $storeId);
        $closingCash = $openingCash + $netChange;
        $closingCashActual = $this->cashBalanceAsOf($to, $storeId);

        return [
            'sections' => $sections,
            'opening_cash' => $openingCash,
            'net_change' => $netChange,
            'closing_cash' => $closingCash,
            // Dihitung ULANG langsung dari saldo akun kas (bukan cuma
            // opening+netChange) — jaring pengaman untuk membuktikan
            // klasifikasi di atas tidak "membocorkan"/menduplikasi kas,
            // seharusnya SELALU sama dengan closing_cash.
            'closing_cash_actual' => $closingCashActual,
            'is_reconciled' => round($closingCash, 2) === round($closingCashActual, 2),
        ];
    }

    /**
     * Saldo gabungan semua akun is_cash=true per tanggal cutoff —
     * dipakai sebagai saldo awal/akhir Laporan Arus Kas DAN sebagai
     * rekonsiliasi silang (is_reconciled) di atas.
     */
    private function cashBalanceAsOf(Carbon $asOf, ?int $storeId = null): float
    {
        $cashAccountIds = ChartOfAccount::where('is_cash', true)->pluck('id');

        return (float) JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.chart_of_account_id', $cashAccountIds)
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '<=', $asOf->toDateString())
            ->when($storeId, fn ($q) => $q->where('journal_entries.store_id', $storeId))
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit - journal_entry_lines.credit), 0) as balance')
            ->value('balance');
    }
}
