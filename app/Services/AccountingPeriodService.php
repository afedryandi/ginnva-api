<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
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

        if (AccountingPeriod::isClosedFor($start)) {
            throw new RuntimeException('Periode ' . $start->translatedFormat('F Y') . ' sudah ditutup sebelumnya.');
        }

        $draftCount = JournalEntry::where('status', 'draft')
            ->whereYear('entry_date', $start->year)
            ->whereMonth('entry_date', $start->month)
            ->count();

        if ($draftCount > 0) {
            throw new RuntimeException("Masih ada {$draftCount} jurnal berstatus Draft di bulan ini — posting atau hapus dulu sebelum menutup periode, supaya tidak ada jurnal yang terjebak tidak bisa diproses lagi.");
        }

        return AccountingPeriod::create([
            'period_month' => $start->toDateString(),
            'closed_by' => $userId,
            'closed_at' => now(),
            'notes' => $notes,
        ]);
    }

    /**
     * Buka kembali periode yang sudah ditutup — dipakai kalau ternyata
     * ada koreksi yang perlu dibuatkan jurnal BARU dengan tanggal di
     * bulan itu (bukan lewat jurnal pembalik bertanggal hari ini).
     * TIDAK ada pembatasan tambahan di sini — keputusan buka kembali
     * sepenuhnya di tangan full-access, riwayatnya tercatat lewat
     * activity log (lihat AccountingPeriod::getActivitylogOptions()).
     */
    public function reopen(AccountingPeriod $period): void
    {
        $period->delete();
    }
}
