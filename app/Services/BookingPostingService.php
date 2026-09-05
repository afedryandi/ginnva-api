<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use RuntimeException;

/**
 * Auto-posting Booking selesai → Jurnal Umum Pendapatan. Dipicu dari
 * aksi "Proses Referral" di BookingResource — SATU-SATUNYA tempat
 * transaction_amount diisi/diubah oleh kasir setelah booking berstatus
 * 'completed' (lihat komentar di BookingResource, nominal transaksi
 * SENGAJA dipisah dari alur "Selesaikan Booking" mobile app).
 *
 * ASUMSI PENYEDERHANAAN (didokumentasikan, bukan disembunyikan):
 * - Seluruh transaction_amount dianggap DITERIMA TUNAI saat kasir
 *   menyimpannya (Debit Kas) — sistem ini belum melacak DP/piutang
 *   terpisah dari pelunasan akhir, jadi tidak ada dasar data untuk
 *   memecah sebagian ke 2140 Pendapatan Diterima Dimuka / 1110
 *   Piutang Usaha. Kalau nanti Booking mendukung pencatatan DP
 *   terpisah, logic ini perlu direvisi.
 * - Booking BISA punya product_ppf DAN product_kaca_film sekaligus,
 *   tapi cuma py 1 transaction_amount (tidak ada rincian per produk)
 *   — kalau keduanya true, nominal dibagi RATA 50/50 ke akun 4100 (PPF)
 *   & 4200 (Kaca Film), bukan ditumpuk semua ke satu akun. Ini
 *   penyederhanaan yang jujur ditampilkan (bukan estimasi tersembunyi),
 *   didokumentasikan supaya siapa pun yang baca laporan tahu batasannya.
 * - entry_date = TANGGAL KASIR MENYIMPAN (hari ini), bukan tanggal
 *   booking selesai — cash-basis, sama pola dengan PayrollPostingService.
 */
class BookingPostingService
{
    private const CASH_ACCOUNT_CODE = '1101';
    private const PPF_REVENUE_ACCOUNT_CODE = '4100';
    private const KACA_FILM_REVENUE_ACCOUNT_CODE = '4200';
    private const FALLBACK_REVENUE_ACCOUNT_CODE = '4400';

    /**
     * Sinkronkan jurnal Pendapatan booking ini dengan transaction_amount
     * TERKINI — dipanggil setiap kali "Proses Referral" disimpan, baik
     * pertama kali maupun koreksi nominal berikutnya.
     *
     * - transaction_amount kosong/≤0: jurnal lama (kalau ada) dibalik,
     *   link dilepas, return null.
     * - transaction_amount terisi: jurnal lama (kalau ada) dibalik dulu,
     *   jurnal baru dibuat dari nominal TERKINI.
     *
     * @throws RuntimeException diteruskan dari JournalEntryService
     *         (mis. akun tidak ditemukan, periode sudah ditutup).
     */
    public function sync(Booking $booking): ?JournalEntry
    {
        $this->reverseExisting($booking);

        $amount = (float) ($booking->transaction_amount ?? 0);

        if ($amount <= 0) {
            $booking->update(['journal_entry_id' => null]);

            return null;
        }

        $entry = $this->post($booking, $amount);
        $booking->update(['journal_entry_id' => $entry->id]);

        return $entry;
    }

    private function post(Booking $booking, float $amount): JournalEntry
    {
        $cash = ChartOfAccount::where('code', self::CASH_ACCOUNT_CODE)->first();
        if (! $cash) {
            throw new RuntimeException('Akun kas (kode ' . self::CASH_ACCOUNT_CODE . ') tidak ditemukan di Bagan Akun.');
        }

        $revenueSplits = $this->revenueSplits($booking, $amount);

        $lines = [['chart_of_account_id' => $cash->id, 'debit' => $amount]];
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
}
