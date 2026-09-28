<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Satu-satunya jalur resmi tutup/buka kembali periode akuntansi —
 * konsisten dengan pola JournalEntryService (semua tulis-menulis lewat
 * service, bukan Eloquent create()/delete() langsung dari Resource).
 */
class AccountingPeriodService
{
    /**
     * @throws RuntimeException kalau periode ini sudah ditutup
     *         sebelumnya, atau masih ada jurnal DRAFT dengan entry_date
     *         di bulan ini (harus diposting atau dihapus dulu — kalau
     *         dibiarkan, draft itu akan terjebak permanen tidak bisa
     *         diposting lagi setelah periodenya ditutup).
     */
    public function close(Carbon $month, ?int $userId, ?string $notes = null): AccountingPeriod
    {
        $start = $month->copy()->startOfMonth();

        // Periode berjalan & masa depan belum boleh ditutup (audit Jurnal Umum
        // 2026-09-29): jurnal masih akan masuk, dan menutupnya lebih awal
        // mengunci pembukuan yang belum selesai.
        if ($start->gte(now()->startOfMonth())) {
            throw new RuntimeException('Hanya periode yang sudah lewat (sebelum bulan ini) yang bisa ditutup.');
        }

        return DB::transaction(function () use ($start, $userId, $notes) {
            if (AccountingPeriod::isClosedFor($start)) {
                throw new RuntimeException('Periode ' . $start->translatedFormat('F Y') . ' sudah ditutup sebelumnya.');
            }

            // Baris periode dibuat LEBIH DULU, baru draft & keseimbangan dicek --
            // jurnal baru yang masuk setelah baris ini commit sudah ditolak
            // assertPeriodOpen(), jadi celah draft "terperangkap" menyempit
            // (audit 2026-09-29). Semua exception di bawah me-rollback baris ini.
            $period = AccountingPeriod::create([
                'period_month' => $start->toDateString(),
                'closed_by' => $userId,
                'closed_at' => now(),
                'notes' => $notes,
            ]);

            $draftCount = JournalEntry::where('status', 'draft')
                ->whereYear('entry_date', $start->year)
                ->whereMonth('entry_date', $start->month)
                ->count();

            if ($draftCount > 0) {
                throw new RuntimeException("Masih ada {$draftCount} jurnal berstatus Draft di bulan ini — posting atau hapus dulu sebelum menutup periode, supaya tidak ada jurnal yang terjebak tidak bisa diproses lagi.");
            }

            // Neraca saldo periode harus seimbang (total debit = kredit dari
            // semua jurnal POSTED bulan ini, dalam sen) sebelum dikunci.
            $totals = DB::table('journal_entry_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('journal_entries.status', 'posted')
                ->whereYear('journal_entries.entry_date', $start->year)
                ->whereMonth('journal_entries.entry_date', $start->month)
                ->selectRaw('COALESCE(SUM(ROUND(journal_entry_lines.debit * 100)), 0) as d, COALESCE(SUM(ROUND(journal_entry_lines.credit * 100)), 0) as c')
                ->first();

            if ((int) $totals->d !== (int) $totals->c) {
                throw new RuntimeException('Total debit dan kredit jurnal posted bulan ini tidak seimbang (selisih Rp' . number_format(abs($totals->d - $totals->c) / 100, 2, ',', '.') . '). Periksa jurnalnya sebelum menutup periode.');
            }

            return $period;
        });
    }

    /**
     * Buka kembali periode yang sudah ditutup — dipakai kalau ternyata
     * ada koreksi yang perlu dibuatkan jurnal BARU dengan tanggal di
     * bulan itu (bukan lewat jurnal pembalik bertanggal hari ini).
     * TIDAK ada pembatasan tambahan di sini — keputusan buka kembali
     * sepenuhnya di tangan full-access, riwayatnya tercatat lewat
     * activity log (lihat AccountingPeriod::getActivitylogOptions()).
     */
    public function reopen(AccountingPeriod $period, ?int $userId = null, string $reason = ''): void
    {
        if (blank($reason)) {
            throw new RuntimeException('Alasan membuka kembali periode wajib diisi.');
        }

        // Alasan & pelaku dicatat di activity log (sebelumnya periode dihapus
        // begitu saja tanpa jejak alasan).
        activity('accounting_period')
            ->performedOn($period)
            ->causedBy($userId ? \App\Models\User::find($userId) : null)
            ->withProperties(['reason' => $reason, 'period_month' => $period->period_month?->toDateString()])
            ->log('Periode ' . $period->period_month?->translatedFormat('F Y') . " dibuka kembali. Alasan: {$reason}");

        $period->delete();
    }
}
