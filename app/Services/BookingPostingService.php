<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Receivable;
use RuntimeException;

/**
 * Auto-posting Booking selesai → Jurnal Umum Pendapatan. Dipicu dari
 * aksi "Proses Referral" di BookingResource — SATU-SATUNYA tempat
 * transaction_amount/amount_received diisi/diubah oleh kasir setelah
 * booking berstatus 'completed' (lihat komentar di BookingResource,
 * nominal transaksi SENGAJA dipisah dari alur "Selesaikan Booking"
 * mobile app).
 *
 * PIUTANG USAHA: amount_received (nullable) memisahkan nominal yang
 * BENAR-BENAR diterima tunai dari transaction_amount (nilai transaksi
 * PENUH). Kalau amount_received < transaction_amount, selisihnya
 * dicatat sebagai Piutang Usaha (1110) lewat ReceivableService — kalau
 * kasir tidak isi amount_received sama sekali, DEFAULT-nya dianggap
 * SAMA dengan transaction_amount (lunas penuh), 100% backward
 * compatible dengan perilaku sebelum Piutang Usaha ada.
 *
 * ASUMSI PENYEDERHANAAN LAIN (didokumentasikan, bukan disembunyikan):
 * - Booking BISA punya product_ppf DAN product_kaca_film sekaligus,
 *   tapi cuma 1 transaction_amount (tidak ada rincian per produk) —
 *   kalau keduanya true, nominal dibagi RATA 50/50 ke akun 4100 (PPF)
 *   & 4200 (Kaca Film), bukan ditumpuk semua ke satu akun.
 * - entry_date = TANGGAL KASIR MENYIMPAN (hari ini), bukan tanggal
 *   booking selesai — cash-basis, sama pola dengan PayrollPostingService.
 */
class BookingPostingService
{
    private const CASH_ACCOUNT_CODE = '1101';
    private const PIUTANG_USAHA_ACCOUNT_CODE = '1110';
    private const PPF_REVENUE_ACCOUNT_CODE = '4100';
    private const KACA_FILM_REVENUE_ACCOUNT_CODE = '4200';
    private const FALLBACK_REVENUE_ACCOUNT_CODE = '4400';

    /**
     * Sinkronkan jurnal Pendapatan booking ini dengan transaction_amount/
     * amount_received TERKINI — dipanggil setiap kali "Proses Referral"
     * disimpan, baik pertama kali maupun koreksi nominal berikutnya.
     *
     * - transaction_amount kosong/≤0: jurnal & piutang lama (kalau ada)
     *   dibalik/dihapus, link dilepas, return null.
     * - transaction_amount terisi: jurnal lama dibalik dulu, jurnal
     *   baru dibuat dari nominal TERKINI. Selisih dari amount_received
     *   jadi Piutang Usaha baru.
     *
     * @throws RuntimeException diteruskan dari JournalEntryService, ATAU
     *         kalau piutang booking ini SUDAH ADA PEMBAYARAN masuk —
     *         nominal transaksi TIDAK BOLEH diubah lagi sebelum piutang
     *         lama diselesaikan/dihapus manual dulu (supaya riwayat
     *         pelunasan tidak hilang diam-diam).
     */
    public function sync(Booking $booking): ?JournalEntry
    {
        $this->assertReceivableSafeToReplace($booking);

        $amount = (float) ($booking->transaction_amount ?? 0);

        $this->reverseExisting($booking);
        $this->clearReplaceableReceivable($booking);

        if ($amount <= 0) {
            $booking->update(['journal_entry_id' => null]);

            return null;
        }

        $received = min((float) ($booking->amount_received ?? $amount), $amount);
        $outstanding = round($amount - $received, 2);

        $entry = $this->post($booking, $amount, $received, $outstanding);
        $booking->update(['journal_entry_id' => $entry->id]);

        if ($outstanding > 0) {
            app(ReceivableService::class)->create([
                'customer_name' => $booking->customer_name,
                'store_id' => $booking->store_id,
                'source_type' => 'booking',
                'source_id' => $booking->id,
                'amount' => $outstanding,
                'due_date' => null,
                'journal_entry_id' => $entry->id,
                'created_by' => auth()->id(),
            ]);
        }

        return $entry;
    }

    private function post(Booking $booking, float $amount, float $received, float $outstanding): JournalEntry
    {
        $lines = [];

        if ($received > 0) {
            $cash = ChartOfAccount::where('code', self::CASH_ACCOUNT_CODE)->first();
            if (! $cash) {
                throw new RuntimeException('Akun kas (kode ' . self::CASH_ACCOUNT_CODE . ') tidak ditemukan di Bagan Akun.');
            }
            $lines[] = ['chart_of_account_id' => $cash->id, 'debit' => $received];
        }

        if ($outstanding > 0) {
            $piutang = ChartOfAccount::where('code', self::PIUTANG_USAHA_ACCOUNT_CODE)->first();
            if (! $piutang) {
                throw new RuntimeException('Akun Piutang Usaha (kode ' . self::PIUTANG_USAHA_ACCOUNT_CODE . ') tidak ditemukan di Bagan Akun.');
            }
            $lines[] = ['chart_of_account_id' => $piutang->id, 'debit' => $outstanding];
        }

        $revenueSplits = $this->revenueSplits($booking, $amount);
        foreach ($revenueSplits as $accountCode => $portion) {
            $account = ChartOfAccount::where('code', $accountCode)->first();
            if (! $account) {
                throw new RuntimeException("Akun pendapatan (kode {$accountCode}) tidak ditemukan di Bagan Akun.");
            }
            $lines[] = ['chart_of_account_id' => $account->id, 'credit' => $portion];
        }

        $service = app(JournalEntryService::class);

        $entry = $service->create([
            'entry_date' => now()->toDateString(),
            'store_id' => $booking->store_id,
            'description' => "Pendapatan booking {$booking->booking_number} — {$booking->customer_name}",
            'reference_type' => 'booking',
            'reference_id' => $booking->id,
            'created_by' => auth()->id(),
        ], $lines);

        return $service->post($entry, auth()->id());
    }

    /**
     * @return array<string, float> kode akun => nominal
     */
    private function revenueSplits(Booking $booking, float $amount): array
    {
        if ($booking->product_ppf && $booking->product_kaca_film) {
            $half = round($amount / 2, 2);

            return [
                self::PPF_REVENUE_ACCOUNT_CODE => $half,
                // Sisa (bukan $half lagi) dipakai untuk baris kedua —
                // supaya total 2 baris SELALU persis sama dengan $amount
                // walau $amount ganjil (pembulatan $half tidak membuang
                // recehan).
                self::KACA_FILM_REVENUE_ACCOUNT_CODE => round($amount - $half, 2),
            ];
        }

        if ($booking->product_ppf) {
            return [self::PPF_REVENUE_ACCOUNT_CODE => $amount];
        }

        if ($booking->product_kaca_film) {
            return [self::KACA_FILM_REVENUE_ACCOUNT_CODE => $amount];
        }

        return [self::FALLBACK_REVENUE_ACCOUNT_CODE => $amount];
    }

    private function reverseExisting(Booking $booking): void
    {
        if (! $booking->journal_entry_id) {
            return;
        }

        $existing = JournalEntry::find($booking->journal_entry_id);

        if ($existing && $existing->isPosted() && ! $existing->reversal()->exists()) {
            app(JournalEntryService::class)->reverse($existing, auth()->id(), 'Nominal transaksi booking diubah');
        }
    }

    /**
     * @throws RuntimeException kalau piutang booking ini sudah pernah
     *         menerima pelunasan — mengubah nominal transaksi lagi akan
     *         membuat piutang lama "yatim"/tidak sinkron dengan
     *         riwayat pelunasan yang sudah tercatat.
     */
    private function assertReceivableSafeToReplace(Booking $booking): void
    {
        $existing = Receivable::where('source_type', 'booking')->where('source_id', $booking->id)->first();

        if ($existing && (float) $existing->amount_paid > 0) {
            throw new RuntimeException("Booking ini punya Piutang Usaha ({$existing->receivable_number}) yang SUDAH ADA pelunasan masuk — selesaikan atau tangani piutang itu dulu lewat menu Piutang Usaha sebelum mengubah nominal transaksi.");
        }
    }

    /**
     * Hapus piutang lama booking ini KALAU BELUM ADA pelunasan sama
     * sekali — aman dihapus & diganti baru, TIDAK ADA riwayat yang
     * hilang. Dipanggil setelah assertReceivableSafeToReplace() lolos.
     */
    private function clearReplaceableReceivable(Booking $booking): void
    {
        Receivable::where('source_type', 'booking')
            ->where('source_id', $booking->id)
            ->where('amount_paid', 0)
            ->delete();
    }
}
