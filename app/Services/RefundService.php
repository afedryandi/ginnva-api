<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ChartOfAccount;
use App\Models\Receivable;
use App\Models\Refund;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\DB;
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
 * booking-nya PPF+Kaca Film — lihat BookingRevenueSplitter, SATU-SATUNYA
 * implementasi split ini, dipakai bareng BookingPostingService — Kredit
 * Kas sebesar nominal refund) — BUKAN
 * mengedit/membalik total JournalEntry ASLI booking itu. Praktik
 * akuntansi standar: entry historis tidak pernah diedit, koreksi selalu
 * lewat entry baru. Ini juga TIDAK memanggil BookingPostingService::sync()
 * — refund sengaja dipisah total dari alur "Proses Referral" supaya
 * tidak mengganggu logika Piutang Usaha yang sudah ada.
 *
 * PIUTANG (audit Piutang Usaha 2026-09-29): bagian booking yang masih Piutang
 * Usaha belum pernah masuk kas, jadi refund atasnya mengurangi piutang (Kredit
 * 1110), bukan mengeluarkan kas. Piutang dikurangi dulu; sisa refund baru
 * jadi kas keluar (Kredit Kas), dibatasi uang yang benar-benar sudah diterima.
 */
class RefundService
{
    private const CASH_ACCOUNT_CODE = '1101';

    /**
     * @throws RuntimeException kalau booking belum punya jurnal
     *         pendapatan (belum "Proses Referral"), nominal refund <= 0,
     *         atau melebihi sisa yang bisa di-refund.
     */
    public function process(Booking $booking, float $amount, ?string $reason, ?int $userId): Refund
    {
        if ($amount <= 0) {
            throw new RuntimeException('Nominal refund harus lebih dari Rp 0.');
        }

        // Audit framework 2026-09-14, "Integritas transaksi finansial"
        // -- SEBELUMNYA "sisa yang bisa di-refund" dihitung TANPA lock
        // & TANPA transaction sama sekali: 2 refund untuk booking yang
        // sama diajukan hampir bersamaan bisa dua-duanya membaca
        // $alreadyRefunded yang SAMA (belum saling lihat punya masing-
        // masing), dua-duanya lolos validasi "tidak melebihi remaining",
        // dan total refund akhirnya melebihi transaction_amount --
        // uang keluar lebih dari yang seharusnya. lockForUpdate() di
        // baris pertama mengunci booking ini supaya refund kedua HARUS
        // menunggu refund pertama commit dulu sebelum mulai menghitung
        // ulang sisa yang benar.
        return DB::transaction(function () use ($booking, $amount, $reason, $userId) {
            $booking = Booking::query()->where('id', $booking->id)->lockForUpdate()->firstOrFail();

            if (! $booking->journal_entry_id) {
                throw new RuntimeException('Booking ini belum punya jurnal pendapatan (belum diproses lewat "Proses Referral") — tidak ada yang bisa di-refund.');
            }

            $alreadyRefunded = (float) $booking->refunds()->sum('amount');
            $remaining = round((float) $booking->transaction_amount - $alreadyRefunded, 2);

            if ($amount > $remaining) {
                throw new RuntimeException("Nominal refund (Rp" . number_format($amount, 0, ',', '.') . ") melebihi sisa yang bisa di-refund (Rp" . number_format($remaining, 0, ',', '.') . ').');
            }

            // Audit Piutang Usaha 2026-09-29: bagian booking yang masih Piutang Usaha belum pernah
            // masuk kas, jadi refund atasnya TIDAK mengeluarkan kas -- cukup mengurangi (menghapus)
            // piutangnya (Kredit 1110). Piutang dikurangi DULU; sisa refund baru jadi kas keluar,
            // dan kas keluar dibatasi uang yang benar-benar sudah diterima (amount_received termasuk
            // bagian piutang yang sudah dihapus lewat refund, jadi dikurangi total refund = kas bersih).
            $receivable = Receivable::withoutGlobalScopes()
                ->where('source_type', 'booking')
                ->where('source_id', $booking->id)
                ->whereIn('status', ['unpaid', 'partial'])
                ->lockForUpdate()
                ->first();

            $outstandingCents = $receivable
                ? (int) round(((float) $receivable->amount - (float) $receivable->amount_paid) * 100)
                : 0;
            $amountCents = (int) round($amount * 100);
            $reduceCents = min($amountCents, max($outstandingCents, 0));
            $cashCents = $amountCents - $reduceCents;

            $received = $booking->amount_received !== null ? (float) $booking->amount_received : (float) $booking->transaction_amount;
            $cashAvailableCents = (int) round(($received - $alreadyRefunded) * 100);

            if ($cashCents > $cashAvailableCents) {
                throw new RuntimeException('Nominal refund (Rp' . number_format($amount, 0, ',', '.') . ') melebihi uang yang sudah diterima dari customer dan piutang yang masih ada. Maksimal yang bisa di-refund sekarang Rp' . number_format(max(($cashAvailableCents + $outstandingCents) / 100, 0), 0, ',', '.') . '.');
            }

            $reduce = $reduceCents / 100;
            $cashPart = $cashCents / 100;

            $cash = ChartOfAccount::where('code', self::CASH_ACCOUNT_CODE)->first();
            if ($cashPart > 0 && ! $cash) {
                throw new RuntimeException('Akun kas (kode ' . self::CASH_ACCOUNT_CODE . ') tidak ditemukan di Bagan Akun.');
            }

            $piutangAccount = null;
            if ($reduce > 0) {
                $piutangAccount = ChartOfAccount::where('code', '1110')->first();
                if (! $piutangAccount) {
                    throw new RuntimeException('Akun Piutang Usaha (kode 1110) tidak ditemukan di Bagan Akun.');
                }
            }

            $lines = [];
            foreach (BookingRevenueSplitter::splits($booking, $amount) as $accountCode => $portion) {
                $account = ChartOfAccount::where('code', $accountCode)->first();
                if (! $account) {
                    throw new RuntimeException("Akun pendapatan (kode {$accountCode}) tidak ditemukan di Bagan Akun.");
                }
                $lines[] = ['chart_of_account_id' => $account->id, 'debit' => $portion];
            }
            if ($reduce > 0) {
                $lines[] = ['chart_of_account_id' => $piutangAccount->id, 'credit' => $reduce];
            }
            if ($cashPart > 0) {
                $lines[] = ['chart_of_account_id' => $cash->id, 'credit' => $cashPart];
            }

            $refundNumber = Refund::generateRefundNumber();

            $service = app(JournalEntryService::class);
            $entry = $service->create([
                'entry_date' => now()->toDateString(),
                'store_id' => $booking->store_id,
                'description' => "Refund {$refundNumber} — booking {$booking->booking_number} ({$booking->display_customer_name})" . ($reason ? " — {$reason}" : ''),
                'reference_type' => 'refund',
                'reference_id' => $booking->id,
                'created_by' => $userId,
            ], $lines);
            $entry = $service->post($entry, $userId);

            if ($receivable && $reduce > 0) {
                $newAmountCents = (int) round(((float) $receivable->amount) * 100) - $reduceCents;
                $paidCents = (int) round(((float) $receivable->amount_paid) * 100);

                if ($newAmountCents <= 0) {
                    // Seluruh piutang dihapus refund (belum ada pelunasan): ditutup sebagai
                    // dibatalkan; nominal aslinya dibiarkan (CHECK amount > 0), sisa dihitung 0.
                    $receivable->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                        'cancelled_by' => $userId,
                        'cancel_reason' => "Dihapus oleh refund {$refundNumber}",
                    ]);
                } else {
                    $receivable->update([
                        'amount' => $newAmountCents / 100,
                        'status' => $paidCents >= $newAmountCents ? 'paid' : ($paidCents > 0 ? 'partial' : 'unpaid'),
                    ]);
                }

                // Bagian piutang yang dihapus dihitung "terselesaikan" di booking supaya
                // dashboard/laporan tidak menampilkannya sebagai belum lunas.
                if ($booking->amount_received !== null) {
                    DB::table('bookings')->where('id', $booking->id)->update([
                        'amount_received' => min((float) $booking->transaction_amount, (float) $booking->amount_received + $reduce),
                    ]);
                }
            }

            $refund = Refund::create([
                'refund_number' => $refundNumber,
                'booking_id' => $booking->id,
                'amount' => $amount,
                'receivable_reduced' => $reduce,
                'reason' => $reason,
                'journal_entry_id' => $entry->id,
                'created_by' => $userId,
            ]);

            // Audit Notifikasi 2026-10-01: SEBELUMNYA customer tidak pernah
            // diberi tahu sama sekali kalau refund booking-nya sudah
            // diproses -- staff (pengaju & approver, lewat approval
            // berjenjang) sudah dapat notif lewat
            // TransactionApprovalService::notifyRequesterOfDecision(), tapi
            // customer yang uangnya di-refund tidak. Dikirim SETELAH commit
            // (di luar transaction ini secara efektif tidak bisa -- jadi
            // dibungkus try/catch supaya kegagalan push tidak membatalkan
            // refund yang sudah sah).
            if ($booking->customer_id) {
                try {
                    app(PushNotificationService::class)->sendToCustomer(
                        $booking->customer_id,
                        'Refund Diproses',
                        "Refund Rp" . number_format($amount, 0, ',', '.') . " untuk booking #{$booking->booking_number} Anda sudah diproses.",
                        ['type' => 'refund_processed', 'booking_id' => $booking->id, 'route' => "/booking/{$booking->id}/chat"]
                    );
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return $refund;
        });
    }
}
