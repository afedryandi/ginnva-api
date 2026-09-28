<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\FinanceTransaction;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fase 3 — jembatan Transaksi Keuangan (Fase 1, UX sederhana untuk
 * staff toko yang tidak perlu paham debit/kredit) ke Jurnal Umum
 * (Fase 2, pembukuan berpasangan). Tiap Transaksi Keuangan otomatis
 * jadi 1 Jurnal Umum 2 baris, LANGSUNG posted (bukan draft) — staff
 * tidak perlu meninjau/memposting manual, itu justru maksud dari
 * "sederhana" di Fase 1.
 *
 * - Pemasukan (in): Debit Kas, Kredit akun kategori (Pendapatan).
 * - Pengeluaran (out): Debit akun kategori (Beban), Kredit Kas.
 *
 * "Kas" SELALU akun 1101 (Kas di Tangan per toko) — Transaksi Keuangan
 * tidak punya konsep pisah kas-tunai vs bank, jadi disederhanakan ke 1
 * akun kas saja untuk integrasi otomatis ini. Toko-nya sendiri tetap
 * beda per baris jurnal lewat journal_entries.store_id (bukan lewat
 * akun kas yang beda-beda), konsisten dengan keputusan "1 bagan akun
 * untuk semua toko".
 */
class FinanceTransactionPostingService
{
    private const CASH_ACCOUNT_CODE = '1101';

    /**
     * @throws RuntimeException kalau kategori transaksi belum
     *         dihubungkan ke akun Bagan Akun, akun kas tidak ditemukan,
     *         atau periode tanggal transaksi sudah ditutup (diteruskan
     *         dari JournalEntryService).
     */
    public function post(FinanceTransaction $transaction, ?int $actorId = null): JournalEntry
    {
        $category = $transaction->category;

        if (! $category) {
            throw new RuntimeException('Transaksi ini tidak punya kategori — tidak bisa diposting ke Jurnal Umum.');
        }

        // Akun = SNAPSHOT di transaksi (diisi saat dibuat / kategorinya
        // diganti), fallback ke akun kategori untuk transaksi lama yang
        // belum punya snapshot (audit Transaksi Keuangan 2026-09-28).
        $accountId = $transaction->chart_of_account_id ?: $category->chart_of_account_id;

        if (! $accountId) {
            throw new RuntimeException("Kategori \"{$category->name}\" belum dihubungkan ke akun Bagan Akun — hubungkan dulu lewat menu Kategori Keuangan sebelum transaksi ini bisa dicatat.");
        }

        $cashAccount = ChartOfAccount::where('code', self::CASH_ACCOUNT_CODE)->first();
        if (! $cashAccount) {
            throw new RuntimeException('Akun kas (kode ' . self::CASH_ACCOUNT_CODE . ') tidak ditemukan di Bagan Akun.');
        }

        $amount = (float) $transaction->amount;
        $lines = $transaction->type === 'in'
            ? [
                ['chart_of_account_id' => $cashAccount->id, 'debit' => $amount],
                ['chart_of_account_id' => $accountId, 'credit' => $amount],
            ]
            : [
                ['chart_of_account_id' => $accountId, 'debit' => $amount],
                ['chart_of_account_id' => $cashAccount->id, 'credit' => $amount],
            ];

        $label = $transaction->type === 'in' ? 'Pemasukan' : 'Pengeluaran';
        $description = ($transaction->transaction_number ? "[{$transaction->transaction_number}] " : '')
            . "{$label}: {$category->name}" . ($transaction->description ? " — {$transaction->description}" : '');

        $service = app(JournalEntryService::class);

        $entry = $service->create([
            'entry_date' => $transaction->transaction_date->toDateString(),
            'store_id' => $transaction->store_id,
            'description' => $description,
            'reference_type' => 'finance_transaction',
            'reference_id' => $transaction->id,
            'created_by' => $actorId ?? $transaction->created_by,
        ], $lines);

        return $service->post($entry, $actorId ?? $transaction->created_by);
    }

    /**
     * Alasan perubahan/penghapusan transaksi yang sudah diposting dicatat di
     * activity log (siapa, kapan, kenapa) -- audit Transaksi Keuangan 2026-09-28.
     */
    public function logChangeReason(FinanceTransaction $transaction, string $action, string $reason): void
    {
        activity('finance_transaction')
            ->performedOn($transaction)
            ->causedBy(auth()->user())
            ->withProperties(['reason' => $reason])
            ->log("Transaksi {$transaction->transaction_number} {$action}. Alasan: {$reason}");
    }

    /**
     * Tutup Periode untuk perubahan transaksi yang SUDAH diposting (audit
     * Transaksi Keuangan 2026-09-28): reverse() selalu memakai tanggal HARI
     * INI, jadi tanpa cek ini menghapus/mengubah transaksi di periode yang
     * sudah ditutup lolos -- bulan lama tetap memuat pendapatan/beban yang
     * "dihapus" sementara bulan ini memuat pembalik tanpa transaksi asal.
     * Dicek terhadap tanggal LAMA transaksi dan (kalau tanggalnya diubah)
     * tanggal BARU.
     *
     * @throws RuntimeException
     */
    public function assertPeriodsOpenForChange(FinanceTransaction $transaction, ?Carbon $newDate = null): void
    {
        if (! $transaction->journal_entry_id) {
            return;
        }

        $dates = [$transaction->getOriginal('transaction_date') ?? $transaction->transaction_date];
        if ($newDate) {
            $dates[] = $newDate;
        }

        foreach ($dates as $date) {
            $date = Carbon::parse($date);
            if (AccountingPeriod::isClosedFor($date)) {
                throw new RuntimeException('Periode ' . $date->translatedFormat('F Y') . ' sudah ditutup — transaksi bertanggal di periode itu tidak bisa diubah atau dihapus.');
            }
        }
    }

    /**
     * Dipakai saat Transaksi Keuangan DIUBAH — jurnal lama (kalau ada &
     * masih posted) dibalik dulu, baru jurnal baru dibuat dari data
     * transaksi yang SUDAH diperbarui. SELALU resync penuh (bukan cuma
     * kalau field finansial yang berubah) — lebih sederhana & aman
     * daripada logic deteksi field mana yang "material", dan sekalian
     * menyamakan deskripsi jurnal kalau keterangan transaksi diedit.
     *
     * @throws RuntimeException diteruskan dari post() di atas.
     */
    public function resync(FinanceTransaction $transaction, ?int $actorId = null, ?string $reason = null): JournalEntry
    {
        $this->reverseExisting($transaction, $actorId, $reason);

        return $this->post($transaction, $actorId);
    }

    /**
     * Dipakai saat Transaksi Keuangan DIHAPUS — jurnal yang sudah
     * posted TIDAK IKUT terhapus (integritas riwayat pembukuan), cuma
     * dibalik lewat jurnal pembalik baru.
     */
    public function reverseExisting(FinanceTransaction $transaction, ?int $actorId = null, ?string $reason = null): void
    {
        if (! $transaction->journal_entry_id) {
            return;
        }

        // Baris jurnal dikunci (lockForUpdate) sebelum cek 'sudah dibalik?'
        // -- dua hapus/edit bersamaan tidak lagi sama-sama lolos dan membuat
        // dua jurnal pembalik. Pelaku = user yang benar-benar mengubah/menghapus
        // (bukan pembuat transaksi asli).
        DB::transaction(function () use ($transaction, $actorId, $reason) {
            $existing = JournalEntry::whereKey($transaction->journal_entry_id)->lockForUpdate()->first();

            if ($existing && $existing->isPosted() && ! $existing->reversal()->exists()) {
                app(JournalEntryService::class)->reverse($existing, $actorId ?? $transaction->created_by, 'Transaksi Keuangan diubah/dihapus' . ($reason ? " — {$reason}" : ''));
            }
        });
    }
}
