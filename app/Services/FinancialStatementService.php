<?php

namespace App\Services;

use App\Models\AccountingPeriod;
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
    /**
     * Nilai khusus "store_id" untuk filter jurnal PUSAT (journal_entries.store_id IS NULL):
     * jurnal company-wide (gaji pusat, penyusutan, setoran modal) tidak ikut saat memilih
     * toko tertentu, jadi perlu bisa dilihat terpisah (audit Laporan Keuangan 2026-09-29).
     */
    public const COMPANY_WIDE = -1;

    /** Label sumber jurnal (journal_entries.reference_type) untuk kolom "Sumber" di Buku Besar. */
    public const SOURCE_LABELS = [
        'manual' => 'Manual',
        'reversal' => 'Pembalik',
        'booking' => 'Booking',
        'refund' => 'Refund',
        'payable' => 'Hutang Usaha',
        'payable_payment' => 'Bayar Hutang',
        'receivable' => 'Piutang Usaha',
        'receivable_payment' => 'Pelunasan Piutang',
        'finance_transaction' => 'Transaksi Keuangan',
    ];

    /** Urutan & label tipe akun untuk pengelompokan laporan (urutan standar Neraca -> Laba Rugi). */
    public const TYPE_LABELS = [
        'aset' => 'Aset',
        'kewajiban' => 'Kewajiban',
        'modal' => 'Modal',
        'pendapatan' => 'Pendapatan',
        'beban_pokok' => 'Beban Pokok Penjualan (HPP)',
        'beban_operasional' => 'Beban Operasional',
        'pendapatan_lain' => 'Pendapatan Lain-lain',
        'beban_lain' => 'Beban Lain-lain',
        'pajak' => 'Beban Pajak',
    ];

    private function applyStore($query, int $storeId, string $column)
    {
        return $storeId === self::COMPANY_WIDE
            ? $query->whereNull($column)
            : $query->where($column, $storeId);
    }

    /**
     * Catatan konteks di atas laporan (audit Laporan Keuangan 2026-09-29): periode yang sudah
     * ditutup, jurnal DRAFT yang belum masuk angka (draft sengaja dikecualikan), dan selisih
     * saldo akun kontrol vs subledger Piutang/Hutang.
     *
     * @return array<int, array{type: string, text: string}> type: info|warning
     */
    public function reportNotices(Carbon $from, Carbon $to, ?int $storeId = null, bool $listClosed = true, bool $subledger = false): array
    {
        $notices = [];

        if ($listClosed) {
            $closed = AccountingPeriod::whereBetween('period_month', [$from->copy()->startOfMonth()->toDateString(), $to->copy()->endOfMonth()->toDateString()])
                ->orderBy('period_month')
                ->get()
                ->map(fn ($p) => $p->period_month->translatedFormat('M Y'));

            if ($closed->isNotEmpty()) {
                $notices[] = ['type' => 'info', 'text' => 'Periode sudah ditutup (terkunci): ' . $closed->implode(', ') . '.'];
            }
        }

        $drafts = JournalEntry::query()
            ->where('status', 'draft')
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->when($storeId, fn ($q) => $this->applyStore($q, $storeId, 'store_id'))
            ->withSum('lines', 'debit')
            ->get();

        if ($drafts->isNotEmpty()) {
            $notices[] = [
                'type' => 'warning',
                'text' => $drafts->count() . ' jurnal DRAFT (total Rp ' . number_format((float) $drafts->sum('lines_sum_debit'), 0, ',', '.') . ') di rentang ini belum masuk laporan — laporan hanya menghitung jurnal yang sudah diposting.',
            ];
        }

        if ($subledger && ! $storeId && $to->gte(today())) {
            $ar = app(ReceivableService::class)->reconcile();
            $ap = app(PayableService::class)->reconcile();

            if (abs($ar['diff']) >= 0.01) {
                $notices[] = ['type' => 'warning', 'text' => 'Saldo Piutang Usaha (1110) di buku besar berbeda Rp ' . number_format(abs($ar['diff']), 0, ',', '.') . ' dari subledger Piutang — cek menu Piutang Usaha › Cek Rekonsiliasi.'];
            }

            if (abs($ap['diff']) >= 0.01) {
                $notices[] = ['type' => 'warning', 'text' => 'Saldo Hutang Usaha (2110) di buku besar berbeda Rp ' . number_format(abs($ap['diff']), 0, ',', '.') . ' dari subledger Hutang — cek menu Hutang Usaha › Cek Rekonsiliasi.'];
            }
        }

        return $notices;
    }

    /** Nominal -> sen (integer): perbandingan/penjumlahan bebas dari error float. */
    private function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

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
    /**
     * Saldo KUMULATIF 1 akun tunggal per tanggal cutoff — dipakai kartu
     * KPI custom "Tambah Widget" (audit Majoo f46). SAMA formula dengan
     * trialBalance() (debit-kredit sampai $asOf, arah mengikuti
     * normal_balance akun), cuma diringkas ke 1 angka untuk 1 akun
     * spesifik alih-alih semua akun sekaligus.
     */
    public function balanceAsOf(ChartOfAccount $account, Carbon $asOf, ?int $storeId = null): float
    {
        $sum = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entry_lines.chart_of_account_id', $account->id)
            ->where('journal_entries.entry_date', '<=', $asOf->toDateString())
            ->when($storeId, fn ($q) => $this->applyStore($q, $storeId, 'journal_entries.store_id'))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();

        $debit = (float) $sum->debit;
        $credit = (float) $sum->credit;

        return $account->isDebitNormal() ? $debit - $credit : $credit - $debit;
    }

    /**
     * @param bool $resetProfitLoss true (dipakai halaman Neraca Saldo): akun Laba Rugi (pendapatan/beban)
     *        HANYA dihitung dari 1 Januari tahun $asOf, dan laba tahun-tahun sebelumnya (yang belum ditutup ke
     *        Laba Ditahan) ditampilkan sebagai 1 baris ekuitas -- supaya angka per akun cocok dengan Laporan
     *        Laba Rugi dan total tetap seimbang. false (default, dipakai Neraca): perilaku kumulatif lama.
     */
    public function trialBalance(Carbon $asOf, ?int $storeId = null, ?Carbon $from = null, bool $resetProfitLoss = false): array
    {
        $yearStart = Carbon::create($asOf->year, 1, 1);
        // Kalau $from diisi: tiap baris juga membawa saldo awal (sebelum $from) dan mutasi periode
        // ($from..$asOf) -- format Neraca Saldo standar (Saldo Awal | Mutasi | Saldo Akhir).
        $periodSelect = $from
            ? ", SUM(CASE WHEN journal_entries.entry_date < ? THEN journal_entry_lines.debit ELSE 0 END) as open_debit"
              . ", SUM(CASE WHEN journal_entries.entry_date < ? THEN journal_entry_lines.credit ELSE 0 END) as open_credit"
            : '';
        $bindings = $from ? [$from->toDateString(), $from->toDateString()] : [];

        $sums = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.entry_date', '<=', $asOf->toDateString())
            ->when($resetProfitLoss, fn ($q) => $q
                ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_entry_lines.chart_of_account_id')
                ->where(fn ($w) => $w
                    ->whereNotIn('chart_of_accounts.type', self::INCOME_STATEMENT_TYPES)
                    ->orWhere('journal_entries.entry_date', '>=', $yearStart->toDateString())))
            ->when($storeId, fn ($q) => $this->applyStore($q, $storeId, 'journal_entries.store_id'))
            ->selectRaw('journal_entry_lines.chart_of_account_id, SUM(journal_entry_lines.debit) as debit, SUM(journal_entry_lines.credit) as credit' . $periodSelect, $bindings)
            ->groupBy('journal_entry_lines.chart_of_account_id')
            ->get()
            ->keyBy('chart_of_account_id');

        $accounts = ChartOfAccount::with('parent')->whereIn('id', $sums->keys())
            ->orderBy('code')
            ->get()
            ->keyBy('id');

        $rows = $sums->map(function ($sum) use ($accounts, $from) {
            $account = $accounts[$sum->chart_of_account_id];
            $debit = (float) $sum->debit;
            $credit = (float) $sum->credit;

            // Saldo ditampilkan mengikuti arah saldo normal akunnya —
            // akun debit-normal (Aset/Beban) = debit - kredit, akun
            // kredit-normal (Kewajiban/Modal/Pendapatan) = kredit - debit.
            $balance = $account->isDebitNormal() ? $debit - $credit : $credit - $debit;

            $row = [
                'account' => $account,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balance,
            ];

            if ($from) {
                $openDebit = (float) $sum->open_debit;
                $openCredit = (float) $sum->open_credit;
                $row['opening_balance'] = $account->isDebitNormal() ? $openDebit - $openCredit : $openCredit - $openDebit;
                $row['period_debit'] = round($debit - $openDebit, 2);
                $row['period_credit'] = round($credit - $openCredit, 2);
            }

            return $row;
        })->sortBy(fn ($row) => $row['account']->code)->values();

        // Laba tahun-tahun sebelumnya yang belum ditutup: 1 baris ekuitas sintetis (bukan akun nyata).
        $priorProfit = 0.0;
        if ($resetProfitLoss) {
            $priorProfit = $this->incomeStatement(Carbon::create(1970, 1, 1), $yearStart->copy()->subDay(), $storeId)['laba_bersih'];

            if (abs($priorProfit) >= 0.005) {
                $retained = new ChartOfAccount();
                $retained->forceFill([
                    'code' => '3200*',
                    'name' => 'Laba Ditahan Tahun Sebelumnya (belum ditutup)',
                    'type' => 'modal',
                    'normal_balance' => 'credit',
                ]);
                $retained->setRelation('parent', null);

                $row = [
                    'account' => $retained,
                    'debit' => $priorProfit < 0 ? abs($priorProfit) : 0.0,
                    'credit' => $priorProfit > 0 ? $priorProfit : 0.0,
                    'balance' => $priorProfit,
                ];

                if ($from) {
                    // Laba yang sudah ada SEBELUM $from adalah saldo awal; sisanya (dari $from sampai akhir tahun lalu)
                    // adalah mutasi periode. Kalau $from setelah 1 Januari, seluruh laba tahun lalu sudah saldo awal.
                    $openingProfit = $from->lte($yearStart)
                        ? $this->incomeStatement(Carbon::create(1970, 1, 1), $from->copy()->subDay(), $storeId)['laba_bersih']
                        : $priorProfit;
                    $delta = round($priorProfit - $openingProfit, 2);
                    $row['opening_balance'] = $openingProfit;
                    $row['period_debit'] = $delta < 0 ? abs($delta) : 0.0;
                    $row['period_credit'] = $delta > 0 ? $delta : 0.0;
                }

                $rows = $rows->push($row)->sortBy(fn ($r) => $r['account']->code)->values();
            }
        }

        return [
            'as_of' => $asOf,
            'reset_profit_loss' => $resetProfitLoss,
            'prior_profit' => $priorProfit,
            'rows' => $rows,
            'total_debit' => (float) $rows->sum('debit'),
            'total_credit' => (float) $rows->sum('credit'),
            'is_balanced' => $this->cents($rows->sum('debit')) === $this->cents($rows->sum('credit')),
            'has_period' => $from !== null,
            'from' => $from,
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
            ->when($storeId, fn ($q) => $this->applyStore($q, $storeId, 'journal_entries.store_id'))
            ->selectRaw('journal_entry_lines.chart_of_account_id, SUM(journal_entry_lines.debit) as debit, SUM(journal_entry_lines.credit) as credit')
            ->groupBy('journal_entry_lines.chart_of_account_id')
            ->get()
            ->keyBy('chart_of_account_id');

        $accounts = ChartOfAccount::with('parent')->whereIn('id', $sums->keys())->get()->keyBy('id');

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
            // from/to dipakai ekspor Excel & PDF untuk label periode (sebelumnya tidak dikembalikan
            // sehingga kedua ekspor crash "Undefined array key" -- audit Laba Rugi 2026-09-29).
            'from' => $from,
            'to' => $to,
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
        // Akun Laba Rugi (pendapatan/beban): saldo awal dihitung sejak 1 Januari tahun $from, bukan sejak
        // awal pencatatan -- sama dengan Neraca Saldo & Laporan Laba Rugi (belum ada jurnal penutup tahunan).
        $resetOpening = in_array($account->type, self::INCOME_STATEMENT_TYPES, true);
        $yearStart = Carbon::create($from->year, 1, 1);

        $opening = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->where('journal_entry_lines.chart_of_account_id', $account->id)
            ->where('journal_entries.entry_date', '<', $from->toDateString())
            ->when($resetOpening, fn ($q) => $q->where('journal_entries.entry_date', '>=', $yearStart->toDateString()))
            ->when($storeId, fn ($q) => $this->applyStore($q, $storeId, 'journal_entries.store_id'))
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
            ->when($storeId, fn ($q) => $this->applyStore($q, $storeId, 'journal_entries.store_id'))
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_entry_lines.id')
            ->get([
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
                'journal_entry_lines.description as line_description',
                'journal_entries.id as entry_id',
                'journal_entries.reference_type',
                'journal_entries.created_by',
                'journal_entries.entry_number',
                'journal_entries.entry_date',
                'journal_entries.description as entry_description',
            ]);

        $creators = \App\Models\User::whereIn('id', $lines->pluck('created_by')->filter()->unique())->pluck('name', 'id');

        // Saldo berjalan dijumlah dalam SEN (integer) supaya tidak ada drift float di volume besar.
        $runningCents = $this->cents($openingBalance);
        $rows = $lines->map(function ($line) use (&$runningCents, $account, $creators) {
            $debit = (float) $line->debit;
            $credit = (float) $line->credit;
            $runningCents += $account->isDebitNormal()
                ? $this->cents($debit) - $this->cents($credit)
                : $this->cents($credit) - $this->cents($debit);
            $running = $runningCents / 100;

            return [
                'entry_date' => Carbon::parse($line->entry_date),
                'entry_id' => $line->entry_id,
                'entry_number' => $line->entry_number,
                'source' => self::SOURCE_LABELS[$line->reference_type ?? 'manual'] ?? ucfirst(str_replace('_', ' ', (string) $line->reference_type)),
                'creator' => $line->created_by ? ($creators[$line->created_by] ?? '—') : 'Sistem',
                'description' => $line->line_description ?: $line->entry_description,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $running,
            ];
        });

        return [
            'account' => $account,
            'opening_balance' => $openingBalance,
            'opening_reset_from' => $resetOpening ? $yearStart : null,
            'rows' => $rows,
            'closing_balance' => $runningCents / 100,
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

        // Laba SEMUA tahun sebelum tahun berjalan yang belum dipindahkan ke Laba Ditahan (3200):
        // belum ada jurnal penutup tahunan, jadi tanpa baris ini Neraca tidak balance mulai
        // tahun buku kedua (audit Laporan Keuangan 2026-09-29). Kalau kelak ada jurnal penutup
        // (akun P&L dinolkan + 3200 dikredit), angka ini otomatis mengecil -- tidak dobel.
        $labaTahunLalu = $fiscalYearStart->copy()->subDay()->year >= 1970
            ? $this->incomeStatement(Carbon::create(1970, 1, 1), $fiscalYearStart->copy()->subDay(), $storeId)['laba_bersih']
            : 0.0;

        $totalModal = $totalModalPosted + $labaTahunLalu + $labaTahunBerjalan;
        $totalKewajibanModal = $totalKewajiban + $totalModal;

        // Rasio keuangan dasar (audit Neraca 2026-09-29). "Lancar" = akun dengan induk 1100 (Aset Lancar) /
        // 2100 (Kewajiban Lancar) di Bagan Akun standar. null kalau pembaginya nol.
        $currentAssets = (float) $aset->filter(fn ($r) => $r['account']->parent?->code === '1100')->sum('balance');
        $currentLiabilities = (float) $kewajiban->filter(fn ($r) => $r['account']->parent?->code === '2100')->sum('balance');
        $ratios = [
            'current_assets' => $currentAssets,
            'current_liabilities' => $currentLiabilities,
            'working_capital' => round($currentAssets - $currentLiabilities, 2),
            'current_ratio' => $currentLiabilities > 0.004 ? round($currentAssets / $currentLiabilities, 2) : null,
            'debt_to_equity' => $totalModal > 0.004 ? round($totalKewajiban / $totalModal, 2) : null,
            'debt_to_assets' => $totalAset > 0.004 ? round($totalKewajiban / $totalAset * 100, 1) : null,
        ];

        return [
            'as_of' => $asOf,
            'ratios' => $ratios,
            'aset' => ['rows' => $aset, 'total' => $totalAset],
            'kewajiban' => ['rows' => $kewajiban, 'total' => $totalKewajiban],
            'modal' => [
                'rows' => $modal,
                'total_posted' => $totalModalPosted,
                'laba_tahun_lalu' => $labaTahunLalu,
                'laba_tahun_berjalan' => $labaTahunBerjalan,
                'total' => $totalModal,
            ],
            'total_kewajiban_modal' => $totalKewajibanModal,
            'is_balanced' => $this->cents($totalAset) === $this->cents($totalKewajibanModal),
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
            ->when($storeId, fn ($q) => $this->applyStore($q, $storeId, 'store_id'))
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
        $warnings = [];

        foreach ($entries as $entry) {
            $cashLines = $entry->lines->filter(fn ($l) => $cashAccountIds->contains($l->chart_of_account_id));
            // Dijumlah dalam SEN (integer): total per kategori & saldo akhir bebas dari drift float, jadi
            // pengecekan rekonsiliasi tidak memberi alarm palsu di volume besar (audit Arus Kas 2026-09-29).
            $cashDeltaCents = (int) $cashLines->sum(fn ($l) => $this->cents($l->debit) - $this->cents($l->credit));

            if ($cashDeltaCents === 0) {
                continue;
            }

            $nonCashLines = $entry->lines->reject(fn ($l) => $cashAccountIds->contains($l->chart_of_account_id));
            if ($nonCashLines->isEmpty()) {
                continue;
            }

            $primary = $nonCashLines->sortByDesc(fn ($l) => max((float) $l->debit, (float) $l->credit))->first();
            $category = $primary->account->cash_flow_category ?? 'operasional';

            // Transparansi klasifikasi (audit 2026-09-29): akun tanpa kategori arus kas tidak lagi
            // diam-diam jadi "operasional", dan jurnal yang mencampur kategori ditandai.
            if ($primary->account->cash_flow_category === null) {
                $warnings['nocat-' . $primary->account->id] = "Akun {$primary->account->code} {$primary->account->name} belum punya kategori arus kas — dihitung sebagai Operasional (mis. jurnal {$entry->entry_number}). Lengkapi di Bagan Akun.";
            }

            $categories = $nonCashLines->map(fn ($l) => $l->account->cash_flow_category ?? 'operasional')->unique();
            if ($categories->count() > 1) {
                $warnings['mixed-' . $entry->id] = "Jurnal {$entry->entry_number} mencampur kategori arus kas (" . $categories->implode(', ') . ") — seluruhnya dihitung sebagai {$category}.";
            }

            $buckets[$category]->push([
                'entry_id' => $entry->id,
                'entry_date' => $entry->entry_date,
                'entry_number' => $entry->entry_number,
                'description' => $entry->description,
                // Akun lawan terbesar = "jenis" arus kas ini (mis. Pendapatan Penjualan, Beban Gaji) untuk pengelompokan.
                'group' => $primary->account->code . ' — ' . $primary->account->name,
                'amount' => $cashDeltaCents / 100,
                'amount_cents' => $cashDeltaCents,
            ]);
        }

        $sections = [];
        foreach ($buckets as $key => $rows) {
            // Pengelompokan menurut akun lawan (jenis arus kas) dengan subtotal, urut kode akun.
            $groups = $rows->groupBy('group')->map(fn ($items, $label) => [
                'label' => $label,
                'total' => $items->sum('amount_cents') / 100,
                'rows' => $items->values(),
            ])->sortKeys()->values();

            $sections[$key] = [
                'label' => $labels[$key],
                'groups' => $groups,
                'rows' => $rows->values(),
                'total' => $rows->sum('amount_cents') / 100,
            ];
        }

        $netChangeCents = (int) collect($buckets)->sum(fn ($rows) => $rows->sum('amount_cents'));
        $openingCash = $this->cashBalanceAsOf($from->copy()->subDay(), $storeId);
        $netChange = $netChangeCents / 100;
        $closingCash = ($this->cents($openingCash) + $netChangeCents) / 100;
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
            'is_reconciled' => $this->cents($closingCash) === $this->cents($closingCashActual),
            'warnings' => array_values($warnings),
            'cash_accounts' => $this->cashAccountBreakdown($from, $to, $storeId),
        ];
    }

    /**
     * Rincian per akun kas (Kas di Tangan, Kas di Bank per rekening): saldo awal, mutasi periode, saldo akhir --
     * dipakai mencocokkan dengan rekening koran (audit Arus Kas 2026-09-29).
     *
     * @return array<int, array{account: ChartOfAccount, opening: float, mutation: float, closing: float}>
     */
    private function cashAccountBreakdown(Carbon $from, Carbon $to, ?int $storeId): array
    {
        $accounts = ChartOfAccount::where('is_cash', true)->orderBy('code')->get()->keyBy('id');

        if ($accounts->isEmpty()) {
            return [];
        }

        $sums = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.chart_of_account_id', $accounts->keys())
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.entry_date', '<=', $to->toDateString())
            ->when($storeId, fn ($q) => $this->applyStore($q, $storeId, 'journal_entries.store_id'))
            ->selectRaw(
                'journal_entry_lines.chart_of_account_id as account_id,'
                . ' SUM(CASE WHEN journal_entries.entry_date < ? THEN journal_entry_lines.debit - journal_entry_lines.credit ELSE 0 END) as opening,'
                . ' SUM(CASE WHEN journal_entries.entry_date >= ? THEN journal_entry_lines.debit - journal_entry_lines.credit ELSE 0 END) as mutation',
                [$from->toDateString(), $from->toDateString()]
            )
            ->groupBy('journal_entry_lines.chart_of_account_id')
            ->get()
            ->keyBy('account_id');

        $result = [];
        foreach ($accounts as $id => $account) {
            $row = $sums->get($id);
            $opening = $this->cents($row->opening ?? 0);
            $mutation = $this->cents($row->mutation ?? 0);

            if ($opening === 0 && $mutation === 0) {
                continue;
            }

            $result[] = [
                'account' => $account,
                'opening' => $opening / 100,
                'mutation' => $mutation / 100,
                'closing' => ($opening + $mutation) / 100,
            ];
        }

        return $result;
    }

    /** Saldo gabungan akun kas per tanggal (publik: dipakai ringkasan penutupan periode). */
    public function cashBalanceAt(Carbon $asOf, ?int $storeId = null): float
    {
        return $this->cashBalanceAsOf($asOf, $storeId);
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
            ->where('journal_entries.entry_date', '<=', $asOf->toDateString())
            ->when($storeId, fn ($q) => $this->applyStore($q, $storeId, 'journal_entries.store_id'))
            ->selectRaw('COALESCE(SUM(journal_entry_lines.debit - journal_entry_lines.credit), 0) as balance')
            ->value('balance');
    }
}
