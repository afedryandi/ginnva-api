<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\FinanceTransaction;
use App\Models\JournalEntry;
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
    public function post(FinanceTransaction $transaction): JournalEntry
    {
        $category = $transaction->category;

        if (! $category) {
            throw new RuntimeException('Transaksi ini tidak punya kategori — tidak bisa diposting ke Jurnal Umum.');
        }

        if (! $category->chart_of_account_id) {
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
                ['chart_of_account_id' => $category->chart_of_account_id, 'credit' => $amount],
            ]
            : [
                ['chart_of_account_id' => $category->chart_of_account_id, 'debit' => $amount],
                ['chart_of_account_id' => $cashAccount->id, 'credit' => $amount],
            ];

        $label = $transaction->type === 'in' ? 'Pemasukan' : 'Pengeluaran';
        $description = "{$label}: {$category->name}" . ($transaction->description ? " — {$transaction->description}" : '');

        $service = app(JournalEntryService::class);

        $entry = $service->create([
            'entry_date' => $transaction->transaction_date->toDateString(),
            'store_id' => $transaction->store_id,
            'description' => $description,
            'reference_type' => 'finance_transaction',
            'reference_id' => $transaction->id,
            'created_by' => $transaction->created_by,
        ], $lines);

        return $service->post($entry, $transaction->created_by);
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
    public function resync(FinanceTransaction $transaction): JournalEntry
    {
        $this->reverseExisting($transaction);

        return $this->post($transaction);
    }

    /**
     * Dipakai saat Transaksi Keuangan DIHAPUS — jurnal yang sudah
     * posted TIDAK IKUT terhapus (integritas riwayat pembukuan), cuma
     * dibalik lewat jurnal pembalik baru.
     */
    public function reverseExisting(FinanceTransaction $transaction): void
    {
        if (! $transaction->journal_entry_id) {
            return;
        }

        $existing = JournalEntry::find($transaction->journal_entry_id);

        if ($existing && $existing->isPosted() && ! $existing->reversal()->exists()) {
            app(JournalEntryService::class)->reverse($existing, $transaction->created_by, 'Transaksi Keuangan diubah/dihapus');
        }
    }
}
