<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ChartOfAccount;
use App\Models\Refund;
use RuntimeException;

/**
 * Proses refund booking — diminta 2026-09-09 (analog "Laporan Refund"
 * Majoo). Aturan DIKONFIRMASI user:
 * - Refund BISA PARSIAL, bisa berkali-kali sampai total refund =
 *   transaction_amount.
 * - Setiap refund WAJIB bikin jurnal balik OTOMATIS (kontra) di Jurnal
 *   Umum, BUKAN cuma catatan tanpa dampak akuntansi.
 *
 * PENDEKATAN: bikin JournalEntry BARU yang membalik SEBAGIAN pendapatan
 * (Debit akun Pendapatan sebesar nominal refund, split 50/50 kalau
 * booking-nya PPF+Kaca Film — SAMA PERSIS logika revenueSplits() di
 * BookingPostingService — Kredit Kas sebesar nominal refund) — BUKAN
 * mengedit/membalik total JournalEntry ASLI booking itu. Praktik
 * akuntansi standar: entry historis tidak pernah diedit, koreksi selalu
 * lewat entry baru. Ini juga TIDAK memanggil BookingPostingService::sync()
 * — refund sengaja dipisah total dari alur "Proses Referral" supaya
 * tidak mengganggu logika Piutang Usaha yang sudah ada.
 *
 * ASUMSI: refund SELALU dianggap dibayar tunai balik ke customer (kredit
 * akun Kas) — TIDAK menangani kasus refund terhadap bagian yang masih
 * jadi Piutang Usaha (belum dibayar customer sama sekali). Kalau nanti
 * ada kasus itu, perlu penyesuaian lebih lanjut (kurangi Piutang,
 * bukan Kas).
 */
class RefundService
{
    private const CASH_ACCOUNT_CODE = '1101';
    private const PPF_REVENUE_ACCOUNT_CODE = '4100';
    private const KACA_FILM_REVENUE_ACCOUNT_CODE = '4200';
    private const FALLBACK_REVENUE_ACCOUNT_CODE = '4400';

    /**
     * @throws RuntimeException kalau booking belum punya jurnal
     *         pendapatan (belum "Proses Referral"), nominal refund <= 0,
     *         atau melebihi sisa yang bisa di-refund.
     */
    public function process(Booking $booking, float $amount, ?string $reason, ?int $userId): Refund
    {
        if (! $booking->journal_entry_id) {
            throw new RuntimeException('Booking ini belum punya jurnal pendapatan (belum diproses lewat "Proses Referral") — tidak ada yang bisa di-refund.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('Nominal refund harus lebih dari Rp 0.');
        }

        $alreadyRefunded = (float) $booking->refunds()->sum('amount');
        $remaining = round((float) $booking->transaction_amount - $alreadyRefunded, 2);

        if ($amount > $remaining) {
            throw new RuntimeException("Nominal refund (Rp" . number_format($amount, 0, ',', '.') . ") melebihi sisa yang bisa di-refund (Rp" . number_format($remaining, 0, ',', '.') . ').');
        }

        $cash = ChartOfAccount::where('code', self::CASH_ACCOUNT_CODE)->first();
        if (! $cash) {
            throw new RuntimeException('Akun kas (kode ' . self::CASH_ACCOUNT_CODE . ') tidak ditemukan di Bagan Akun.');
        }

        $lines = [];
        foreach ($this->revenueSplits($booking, $amount) as $accountCode => $portion) {
            $account = ChartOfAccount::where('code', $accountCode)->first();
            if (! $account) {
                throw new RuntimeException("Akun pendapatan (kode {$accountCode}) tidak ditemukan di Bagan Akun.");
            }
            $lines[] = ['chart_of_account_id' => $account->id, 'debit' => $portion];
        }
        $lines[] = ['chart_of_account_id' => $cash->id, 'credit' => $amount];

        $refundNumber = Refund::generateRefundNumber();

        $service = app(JournalEntryService::class);
        $entry = $service->create([
            'entry_date' => now()->toDateString(),
            'store_id' => $booking->store_id,
            'description' => "Refund {$refundNumber} — booking {$booking->booking_number} ({$booking->customer_name})" . ($reason ? " — {$reason}" : ''),
            'reference_type' => 'refund',
            'reference_id' => $booking->id,
            'created_by' => $userId,
        ], $lines);
        $entry = $service->post($entry, $userId);

        return Refund::create([
            'refund_number' => $refundNumber,
            'booking_id' => $booking->id,
            'amount' => $amount,
            'reason' => $reason,
            'journal_entry_id' => $entry->id,
            'created_by' => $userId,
        ]);
    }

    /**
     * @return array<string, float> kode akun => nominal — SAMA PERSIS
     *         logika BookingPostingService::revenueSplits(), diterapkan
     *         ke nominal refund (bukan transaction_amount penuh).
     */
    private function revenueSplits(Booking $booking, float $amount): array
    {
        if ($booking->product_ppf && $booking->product_kaca_film) {
            $half = round($amount / 2, 2);

            return [
                self::PPF_REVENUE_ACCOUNT_CODE => $half,
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
}
