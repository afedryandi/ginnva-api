<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\TransactionApprovalRequest;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Audit framework 2026-09-14, "Segregation of duties finansial" --
 * satu-satunya jalur resmi submit/approve/reject permintaan "Proses
 * Referral"/"Proses Refund" dari staff non-full-access. User full-
 * access TIDAK lewat service ini sama sekali -- mereka tetap bisa
 * panggil BookingPostingService/RefundService langsung (lihat
 * BookingResource), self-approval tidak menambah kontrol apa pun.
 */
class TransactionApprovalService
{
    /**
     * @param  array{referral_code?: ?string, spend_promo_id?: ?int, spend_promo_discount?: ?float}  $extra
     *         Field tambahan yang dibutuhkan supaya approve() nanti bisa
     *         mereplikasi PERSIS langkah yang sama dengan jalur
     *         full-access langsung di BookingResource (simpan promo +
     *         kode referral, kasih poin referral) -- bukan cuma posting
     *         nominal & jurnalnya saja.
     */
    public function submitBookingReferral(Booking $booking, float $transactionAmount, ?float $amountReceived, array $extra, int $requestedBy): TransactionApprovalRequest
    {
        $request = TransactionApprovalRequest::create([
            'type' => 'booking_referral',
            'booking_id' => $booking->id,
            'payload' => array_merge([
                'transaction_amount' => $transactionAmount,
                'amount_received' => $amountReceived,
            ], $extra),
            'status' => 'pending',
            'requested_by' => $requestedBy,
        ]);

        $this->notifyFullAccess($booking, $request);

        return $request;
    }

    public function submitRefund(Booking $booking, float $amount, ?string $reason, int $requestedBy): TransactionApprovalRequest
    {
        $request = TransactionApprovalRequest::create([
            'type' => 'refund',
            'booking_id' => $booking->id,
            'payload' => [
                'amount' => $amount,
                'reason' => $reason,
            ],
            'status' => 'pending',
            'requested_by' => $requestedBy,
        ]);

        $this->notifyFullAccess($booking, $request);

        return $request;
    }

    /**
     * @throws RuntimeException diteruskan dari BookingPostingService/
     *         RefundService kalau data sudah tidak valid lagi saat
     *         akhirnya dieksekusi (mis. booking sudah diubah staff
     *         lain sejak permintaan diajukan).
     */
    public function approve(TransactionApprovalRequest $request, int $approvedBy): void
    {
        if (! $request->isPending()) {
            throw new RuntimeException('Permintaan ini sudah diputuskan sebelumnya.');
        }

        DB::transaction(function () use ($request, $approvedBy) {
            $booking = $request->booking()->lockForUpdate()->firstOrFail();

            if ($request->type === 'booking_referral') {
                $payload = $request->payload;

                $booking->update([
                    'transaction_amount' => $payload['transaction_amount'],
                    'amount_received' => $payload['amount_received'],
                    'referral_code' => $payload['referral_code'] ?? null,
                    'spend_promo_id' => $payload['spend_promo_id'] ?? null,
                    'spend_promo_discount' => $payload['spend_promo_discount'] ?? null,
                ]);

                app(BookingPostingService::class)->sync($booking->refresh());

                // Kasih poin referral SETELAH nominal ter-posting --
                // sama urutan dengan jalur full-access langsung di
                // BookingResource. Kegagalan di sini TIDAK membatalkan
                // approval (nominal/jurnal sudah sah), cuma dicatat.
                $referralService = app(ReferralPointService::class);
                if (! empty($payload['referral_code'])) {
                    try {
                        $referralService->awardForBooking($booking->fresh());
                    } catch (RuntimeException $e) {
                        // Sengaja diabaikan -- sama toleransi dengan
                        // BookingResource, poin partner bukan syarat sah
                        // approval nominal transaksi.
                    }
                }
                try {
                    $referralService->awardForCustomerReferral($booking->fresh());
                } catch (RuntimeException $e) {
                    // Sengaja diabaikan, lihat catatan di atas.
                }
            } elseif ($request->type === 'refund') {
                app(RefundService::class)->process(
                    $booking,
                    (float) $request->payload['amount'],
                    $request->payload['reason'] ?? null,
                    $request->requested_by // pemohon asli tercatat sebagai created_by Refund, approver tercatat di kolom decided_by request ini
                );
            }

            $request->update([
                'status' => 'approved',
                'decided_by' => $approvedBy,
                'decided_at' => now(),
            ]);
        });
    }

    public function reject(TransactionApprovalRequest $request, int $approvedBy, ?string $note): void
    {
        if (! $request->isPending()) {
            throw new RuntimeException('Permintaan ini sudah diputuskan sebelumnya.');
        }

        $request->update([
            'status' => 'rejected',
            'decided_by' => $approvedBy,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);
    }

    private function notifyFullAccess(Booking $booking, TransactionApprovalRequest $request): void
    {
        $label = TransactionApprovalRequest::TYPE_LABELS[$request->type] ?? $request->type;
        $recipients = User::where('is_active', true)->get()->filter(fn (User $u) => $u->isFullAccess());

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title("Menunggu persetujuan: {$label}")
                ->body("Booking {$booking->booking_number} — perlu persetujuan Anda sebelum diposting ke Jurnal Umum.")
                ->warning()
                ->sendToDatabase($recipient);
        }
    }
}
