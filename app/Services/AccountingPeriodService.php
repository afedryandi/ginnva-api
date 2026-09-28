<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AccountingPeriodEvent;
use App\Models\Asset;
use App\Models\BankStatementLine;
use App\Models\FinanceTransactionApprovalRequest;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\TransactionApprovalRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Satu-satunya jalur resmi tutup/buka kembali periode akuntansi —
 * konsisten dengan pola JournalEntryService (semua tulis-menulis lewat
 * service, bukan Eloquent create()/delete() langsung dari Resource).
 *
 * Audit Tutup Periode 2026-09-29: penutupan berurutan, buka kembali dari yang terbaru, daftar periksa
 * (memblokir: draft & tidak seimbang; peringatan: pengajuan pending, payroll/penyusutan belum jalan, mutasi
 * bank belum dicocokkan -- harus dikonfirmasi), ringkasan angka saat ditutup, dan riwayat buka-tutup.
 */
class AccountingPeriodService
{
    /**
     * Daftar periksa penutupan 1 bulan. severity 'block' = tidak bisa ditutup; 'warn' = boleh ditutup
     * setelah dikonfirmasi. Hanya item dengan count > 0 yang dikembalikan.
     *
     * @return array<int, array{key: string, severity: string, count: int, text: string}>
     */
    public function checklist(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $items = [];

        $draftCount = JournalEntry::where('status', 'draft')
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->count();

        if ($draftCount > 0) {
            $items[] = ['key' => 'draft', 'severity' => 'block', 'count' => $draftCount, 'text' => "{$draftCount} jurnal berstatus Draft di bulan ini — posting atau hapus dulu, supaya tidak ada jurnal yang terjebak tidak bisa diproses lagi."];
        }

        $totals = $this->totals($start, $end);
        if ($totals['debit'] !== $totals['credit']) {
            $items[] = ['key' => 'imbalance', 'severity' => 'block', 'count' => 1, 'text' => 'Total debit dan kredit jurnal posted bulan ini tidak seimbang (selisih Rp' . number_format(abs($totals['debit'] - $totals['credit']) / 100, 2, ',', '.') . '). Periksa jurnalnya.'];
        }

        $pendingFinance = FinanceTransactionApprovalRequest::whereIn('status', ['pending_manager', 'pending_direksi'])
            ->where('created_at', '<=', $end->copy()->endOfDay())
            ->count();
        if ($pendingFinance > 0) {
            $items[] = ['key' => 'pending_finance', 'severity' => 'warn', 'count' => $pendingFinance, 'text' => "{$pendingFinance} pengajuan pengeluaran masih menunggu persetujuan — kalau disetujui nanti, jurnalnya tidak bisa bertanggal di bulan ini."];
        }

        $pendingTx = TransactionApprovalRequest::where('status', 'pending')
            ->where('created_at', '<=', $end->copy()->endOfDay())
            ->count();
        if ($pendingTx > 0) {
            $items[] = ['key' => 'pending_transactions', 'severity' => 'warn', 'count' => $pendingTx, 'text' => "{$pendingTx} pengajuan Referral/Refund/DP masih menunggu persetujuan."];
        }

        $payrollDraft = Payroll::where('period_month', $start->toDateString())->where('status', 'draft')->count();
        if ($payrollDraft > 0) {
            $items[] = ['key' => 'payroll', 'severity' => 'warn', 'count' => $payrollDraft, 'text' => "{$payrollDraft} slip gaji bulan ini belum dibayar/dijurnal (status Draft)."];
        }

        // Penyusutan: aset aktif yang sudah dibeli sebelum bulan ini tapi belum punya jurnal penyusutan bulan ini
        // (perkiraan -- aset yang sudah habis disusutkan bisa ikut terhitung).
        $postedDepreciationAssetIds = JournalEntry::where('reference_type', 'asset_depreciation')
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->pluck('reference_id');
        $missingDepreciation = Asset::where('status', 'aktif')
            ->whereNotNull('purchase_date')
            ->where('purchase_date', '<', $start->toDateString())
            ->whereNotIn('id', $postedDepreciationAssetIds)
            ->count();
        if ($missingDepreciation > 0) {
            $items[] = ['key' => 'depreciation', 'severity' => 'warn', 'count' => $missingDepreciation, 'text' => "{$missingDepreciation} aset aktif belum punya jurnal penyusutan bulan ini (perkiraan — aset yang sudah habis disusutkan mungkin ikut terhitung)."];
        }

        $unmatchedBank = BankStatementLine::where('status', 'unmatched')
            ->whereBetween('statement_date', [$start->toDateString(), $end->toDateString()])
            ->count();
        if ($unmatchedBank > 0) {
            $items[] = ['key' => 'bank', 'severity' => 'warn', 'count' => $unmatchedBank, 'text' => "{$unmatchedBank} mutasi rekening koran bulan ini belum dicocokkan dengan jurnal."];
        }

        return $items;
    }

    /**
     * @param bool $acknowledgeWarnings true = pengguna sudah membaca & mengonfirmasi item peringatan (severity 'warn').
     *
     * @throws RuntimeException kalau periode ini sudah ditutup sebelumnya, bulan berjalan/depan, tidak berurutan,
     *         ada item pemblokir (draft/tidak seimbang), atau ada peringatan yang belum dikonfirmasi.
     */
    public function close(Carbon $month, ?int $userId, ?string $notes = null, bool $acknowledgeWarnings = false): AccountingPeriod
    {
        $start = $month->copy()->startOfMonth();

        // Periode berjalan & masa depan belum boleh ditutup (audit Jurnal Umum
        // 2026-09-29): jurnal masih akan masuk, dan menutupnya lebih awal
        // mengunci pembukuan yang belum selesai.
        if ($start->gte(now()->startOfMonth())) {
            throw new RuntimeException('Hanya periode yang sudah lewat (sebelum bulan ini) yang bisa ditutup.');
        }

        return DB::transaction(function () use ($start, $userId, $notes, $acknowledgeWarnings) {
            if (AccountingPeriod::isClosedFor($start)) {
                throw new RuntimeException('Periode ' . $start->translatedFormat('F Y') . ' sudah ditutup sebelumnya.');
            }

            // Penutupan harus BERURUTAN: bulan sebelumnya wajib sudah ditutup, kecuali belum ada jurnal posted
            // sama sekali sebelum bulan ini (bulan pertama pemakaian).
            $previous = $start->copy()->subMonthNoOverflow();
            if (! AccountingPeriod::isClosedFor($previous)
                && JournalEntry::where('status', 'posted')->where('entry_date', '<', $start->toDateString())->exists()) {
                throw new RuntimeException('Tutup periode ' . $previous->translatedFormat('F Y') . ' dulu — periode harus ditutup berurutan dari yang paling lama.');
            }

            // Baris periode dibuat LEBIH DULU, baru daftar periksa dijalankan -- jurnal baru yang masuk setelah
            // baris ini commit sudah ditolak assertPeriodOpen(), jadi celah draft "terperangkap" menyempit.
            // Semua exception di bawah me-rollback baris ini.
            try {
                $period = AccountingPeriod::create([
                    'period_month' => $start->toDateString(),
                    'closed_by' => $userId,
                    'closed_at' => now(),
                    'notes' => $notes,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // 2 orang menutup bulan yang sama bersamaan: yang kedua kalah di unique index period_month.
                throw new RuntimeException('Periode ' . $start->translatedFormat('F Y') . ' baru saja ditutup oleh pengguna lain.');
            }

            $items = $this->checklist($start);

            $blockers = collect($items)->where('severity', 'block');
            if ($blockers->isNotEmpty()) {
                throw new RuntimeException($blockers->pluck('text')->implode(' '));
            }

            $warnings = collect($items)->where('severity', 'warn');
            if ($warnings->isNotEmpty() && ! $acknowledgeWarnings) {
                throw new RuntimeException('Ada peringatan yang perlu dikonfirmasi dulu: ' . $warnings->pluck('text')->implode(' | '));
            }

            $snapshot = $this->snapshot($start) + ['acknowledged_warnings' => $warnings->pluck('key')->values()->all()];
            $period->update(['snapshot' => $snapshot]);

            AccountingPeriodEvent::create([
                'period_month' => $start->toDateString(),
                'action' => 'closed',
                'user_id' => $userId,
                'note' => $notes,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $period;
        });
    }

    /**
     * Buka kembali periode yang sudah ditutup — dipakai kalau ternyata
     * ada koreksi yang perlu dibuatkan jurnal BARU dengan tanggal di
     * bulan itu (bukan lewat jurnal pembalik bertanggal hari ini).
     * Keputusan buka kembali di tangan full-access (alasan wajib, direksi lain diberi notifikasi oleh
     * pemanggil); riwayatnya tercatat di activity log DAN accounting_period_events.
     */
    public function reopen(AccountingPeriod $period, ?int $userId = null, string $reason = ''): void
    {
        if (blank($reason)) {
            throw new RuntimeException('Alasan membuka kembali periode wajib diisi.');
        }

        // Membuka kembali harus dari periode TERBARU yang tertutup (urutan terbalik dari menutup), supaya
        // tidak ada periode lama yang terbuka sementara periode sesudahnya masih terkunci.
        $newer = AccountingPeriod::where('period_month', '>', $period->period_month->toDateString())
            ->orderByDesc('period_month')
            ->first();

        if ($newer) {
            throw new RuntimeException('Buka kembali periode ' . $newer->period_month->translatedFormat('F Y') . ' dulu — periode dibuka dari yang paling baru.');
        }

        // Alasan & pelaku dicatat di activity log (sebelumnya periode dihapus
        // begitu saja tanpa jejak alasan).
        activity('accounting_period')
            ->performedOn($period)
            ->causedBy($userId ? \App\Models\User::find($userId) : null)
            ->withProperties(['reason' => $reason, 'period_month' => $period->period_month?->toDateString()])
            ->log('Periode ' . $period->period_month?->translatedFormat('F Y') . " dibuka kembali. Alasan: {$reason}");

        DB::transaction(function () use ($period, $userId, $reason) {
            AccountingPeriodEvent::create([
                'period_month' => $period->period_month->toDateString(),
                'action' => 'reopened',
                'user_id' => $userId,
                'note' => $reason,
                'snapshot' => $period->snapshot,
                'created_at' => now(),
            ]);

            $period->delete();
        });
    }

    /** Total debit/kredit jurnal posted 1 bulan, dalam SEN (integer). */
    private function totals(Carbon $start, Carbon $end): array
    {
        $row = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.status', 'posted')
            ->whereBetween('journal_entries.entry_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('COALESCE(SUM(ROUND(journal_entry_lines.debit * 100)), 0) as d, COALESCE(SUM(ROUND(journal_entry_lines.credit * 100)), 0) as c')
            ->first();

        return ['debit' => (int) $row->d, 'credit' => (int) $row->c];
    }

    /**
     * Ringkasan angka saat periode ditutup (jumlah jurnal, total, laba bersih, saldo kas akhir).
     *
     * @return array<string, mixed>
     */
    public function snapshot(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $totals = $this->totals($start, $end);
        $statements = app(FinancialStatementService::class);

        return [
            'journal_count' => JournalEntry::where('status', 'posted')->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])->count(),
            'total_debit' => $totals['debit'] / 100,
            'total_credit' => $totals['credit'] / 100,
            'net_income' => $statements->incomeStatement($start, $end)['laba_bersih'],
            'closing_cash' => $statements->cashBalanceAt($end),
        ];
    }
}
