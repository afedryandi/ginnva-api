<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\YearEndClosing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Penutupan tahun buku (Keuangan Fase 6, 2026-11-09): memindahkan laba (rugi) satu tahun ke Laba Ditahan (3200). Satu jurnal penutup bertanggal
 * 31 Desember dibuat untuk tiap toko yang punya aktivitas Laba Rugi (dan satu untuk jurnal tanpa toko), sehingga laporan per toko tetap
 * konsisten: tiap akun Laba Rugi dinolkan lawan 3200 pada toko yang sama. Jurnal penutup ditandai reference_type "year_end_closing" dan
 * DIKECUALIKAN dari Laporan Laba Rugi periode (lihat FinancialStatementService::incomeStatement) supaya Laba Rugi tahun itu tetap menampilkan
 * laba sebenarnya; Neraca dan saldo kumulatif memakainya, jadi tidak dobel dengan baris laba tahun lalu "belum ditutup".
 *
 * Syarat: tahun sudah lewat, belum ditutup, tidak ada jurnal draft di tahun itu, dan periode Desember belum ditutup (jurnal bertanggal 31 Desember
 * ditolak di periode tertutup; tutup tahun dulu, baru tutup Desember). Pembatalan membalik jurnal penutup pada 31 Desember (periode Desember harus
 * terbuka).
 */
class YearEndClosingService
{
    public const REF_TYPE = 'year_end_closing';

    private const PL_TYPES = ['pendapatan', 'beban_pokok', 'beban_operasional', 'pendapatan_lain', 'beban_lain', 'pajak'];

    /** Kondisi SQL (tabel journal_entries tanpa alias) yang membuang jurnal penutup DAN jurnal pembaliknya dari laporan Laba Rugi periode. */
    public const EXCLUDE_CLOSING_SQL = "(journal_entries.reference_type IS NULL OR (journal_entries.reference_type <> 'year_end_closing' AND NOT (journal_entries.reference_type = 'reversal' AND journal_entries.reference_id IN (SELECT je_close.id FROM journal_entries je_close WHERE je_close.reference_type = 'year_end_closing'))))";

    /**
     * @return array{year: int, net_income: float, groups: Collection<int, array{store_id: ?int, lines: list<array{account: ChartOfAccount, net: float}>, net_income: float}>, accounts: int}
     */
    public function preview(int $year): array
    {
        $rows = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_entry_lines.chart_of_account_id')
            ->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', ["{$year}-01-01", "{$year}-12-31"])
            ->whereIn('chart_of_accounts.type', self::PL_TYPES)
            ->whereRaw(self::EXCLUDE_CLOSING_SQL)
            ->selectRaw('journal_entries.store_id as store_id, journal_entry_lines.chart_of_account_id as account_id, SUM(journal_entry_lines.debit) - SUM(journal_entry_lines.credit) as net')
            ->groupBy('journal_entries.store_id', 'journal_entry_lines.chart_of_account_id')
            ->get()
            ->filter(fn ($r) => abs((float) $r->net) >= 0.005);

        $accounts = ChartOfAccount::whereIn('id', $rows->pluck('account_id')->unique())->get()->keyBy('id');

        $groups = $rows->groupBy(fn ($r) => $r->store_id ?? 'null')->map(function ($group) use ($accounts) {
            $lines = $group->map(fn ($r) => ['account' => $accounts[$r->account_id], 'net' => round((float) $r->net, 2)])->sortBy(fn ($l) => $l['account']->code)->values()->all();

            return [
                'store_id' => $group->first()->store_id !== null ? (int) $group->first()->store_id : null,
                'lines' => $lines,
                // net = debit - kredit; laba = kredit - debit seluruh akun Laba Rugi
                'net_income' => round(-array_sum(array_column($lines, 'net')), 2),
            ];
        })->values();

        return [
            'year' => $year,
            'net_income' => round((float) $groups->sum('net_income'), 2),
            'groups' => $groups,
            'accounts' => $rows->pluck('account_id')->unique()->count(),
        ];
    }

    /** @return list<string> alasan tahun ini belum bisa ditutup */
    public function blockers(int $year): array
    {
        $issues = [];

        if (now()->year <= $year) {
            $issues[] = "Tahun {$year} belum selesai; hanya tahun yang sudah lewat yang bisa ditutup.";
        }

        if (YearEndClosing::whereNull('voided_at')->where('year', $year)->exists()) {
            $issues[] = "Tahun {$year} sudah ditutup.";
        }

        $drafts = JournalEntry::withoutGlobalScopes()->where('status', 'draft')->whereBetween('entry_date', ["{$year}-01-01", "{$year}-12-31"])->count();

        if ($drafts > 0) {
            $issues[] = "{$drafts} jurnal berstatus Draft di tahun {$year}: posting atau hapus dulu.";
        }

        if (AccountingPeriod::isClosedFor(Carbon::create($year, 12, 31))) {
            $issues[] = 'Periode Desember ' . $year . ' sudah ditutup. Buka kembali Desember di Tutup Periode, tutup tahun buku, lalu tutup Desember lagi.';
        }

        if ($this->preview($year)['groups']->isEmpty()) {
            $issues[] = "Tidak ada saldo Laba Rugi pada tahun {$year} yang perlu ditutup.";
        }

        return $issues;
    }

    /** @throws RuntimeException */
    public function close(int $year, ?int $userId, ?string $notes = null): YearEndClosing
    {
        $issues = $this->blockers($year);

        if ($issues) {
            throw new RuntimeException(implode(' ', $issues));
        }

        $retained = ChartOfAccount::where('code', '3200')->first();

        if (! $retained) {
            throw new RuntimeException('Akun Laba Ditahan (3200) tidak ditemukan di Bagan Akun.');
        }

        return DB::transaction(function () use ($year, $userId, $notes, $retained) {
            $preview = $this->preview($year);
            $service = app(JournalEntryService::class);
            $count = 0;

            foreach ($preview['groups'] as $group) {
                $lines = [];

                foreach ($group['lines'] as $line) {
                    // Saldo debit ditutup dengan kredit, dan sebaliknya.
                    $lines[] = $line['net'] > 0
                        ? ['chart_of_account_id' => $line['account']->id, 'credit' => $line['net']]
                        : ['chart_of_account_id' => $line['account']->id, 'debit' => abs($line['net'])];
                }

                $income = $group['net_income'];

                if ($income > 0) {
                    $lines[] = ['chart_of_account_id' => $retained->id, 'credit' => $income];
                } elseif ($income < 0) {
                    $lines[] = ['chart_of_account_id' => $retained->id, 'debit' => abs($income)];
                }

                $entry = $service->create([
                    'entry_date' => "{$year}-12-31",
                    'store_id' => $group['store_id'],
                    'description' => "Jurnal penutup tahun buku {$year}",
                    'reference_type' => self::REF_TYPE,
                    'reference_id' => $year,
                    'created_by' => $userId,
                ], $lines);
                $service->post($entry, $userId);
                $count++;
            }

            return YearEndClosing::create([
                'year' => $year,
                'net_income' => $preview['net_income'],
                'entries_count' => $count,
                'notes' => $notes,
                'closed_by' => $userId,
                'closed_at' => now(),
            ]);
        });
    }

    /** @throws RuntimeException */
    public function void(YearEndClosing $closing, ?int $userId, string $reason): YearEndClosing
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($closing, $userId, $reason) {
            $locked = YearEndClosing::whereKey($closing->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw new RuntimeException('Penutupan ini sudah dibatalkan.');
            }

            if (AccountingPeriod::isClosedFor(Carbon::create($locked->year, 12, 31))) {
                throw new RuntimeException('Periode Desember ' . $locked->year . ' sudah ditutup. Buka kembali dulu sebelum membatalkan penutupan tahun.');
            }

            $service = app(JournalEntryService::class);

            JournalEntry::withoutGlobalScopes()
                ->where('reference_type', self::REF_TYPE)->where('reference_id', $locked->year)->where('status', 'posted')->whereDoesntHave('reversal')
                ->get()
                ->each(fn (JournalEntry $entry) => $service->reverse($entry, $userId, "Batal penutupan tahun buku {$locked->year}: {$reason}", "{$locked->year}-12-31"));

            $locked->update(['voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason]);

            return $locked->fresh();
        });
    }
}
