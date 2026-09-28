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
     * @param  array{referral_code?: ?string, spend_promo_id?: ?int, spend_promo_discount?: ?float, voucher_claim_id?: ?int, payment_method?: ?string, vehicle_size?: ?string}  $extra
     *         Field tambahan yang dibutuhkan supaya approve() nanti bisa
     *         mereplikasi PERSIS langkah yang sama dengan jalur
     *         full-access langsung di BookingResource (simpan promo +
     *         kode referral, kasih poin referral) -- bukan cuma posting
     *         nominal & jurnalnya saja.
     */
    public function submitBookingReferral(Booking $booking, float $transactionAmount, ?float $amountReceived, array $extra, int $requestedBy): TransactionApprovalRequest
    {
        $request = $this->createRequest($booking, 'booking_referral', array_merge([
            'transaction_amount' => $transactionAmount,
            'amount_received' => $amountReceived,
        ], $extra), $requestedBy);

        $this->notifyFullAccess($booking, $request);

        return $request;
    }

    public function submitRefund(Booking $booking, float $amount, ?string $reason, int $requestedBy): TransactionApprovalRequest
    {
        $request = $this->createRequest($booking, 'refund', [
            'amount' => $amount,
            'reason' => $reason,
        ], $requestedBy);

        $this->notifyFullAccess($booking, $request);

        return $request;
    }

    /**
     * DP (2026-09-19, Topik 2) IKUT diwajibkan approval full-access untuk
     * staff non-full-access -- konsisten dengan Proses Referral/Refund
     * (audit framework 2026-09-14, "semua nominal wajib approval"), DP
     * tetap uang sungguhan yang keluar/masuk meskipun bukan pendapatan.
     */
    public function submitDownPayment(Booking $booking, float $amount, ?string $notes, int $requestedBy): TransactionApprovalRequest
    {
        $request = $this->createRequest($booking, 'booking_down_payment', [
            'amount' => $amount,
            'notes' => $notes,
        ], $requestedBy);

        $this->notifyFullAccess($booking, $request);

        return $request;
    }

    /**
     * 1 pengajuan PENDING per (booking, jenis) -- audit Persetujuan Transaksi
     * 2026-09-29: sebelumnya staff bisa mengajukan berulang untuk booking yang
     * sama (dua refund pending yang totalnya melebihi sisa, DP ganda). Booking
     * dikunci (lockForUpdate) supaya dua submit bersamaan tidak sama-sama lolos.
     *
     * @throws RuntimeException
     */
    private function createRequest(Booking $booking, string $type, array $payload, int $requestedBy): TransactionApprovalRequest
    {
        return DB::transaction(function () use ($booking, $type, $payload, $requestedBy) {
            Booking::whereKey($booking->id)->lockForUpdate()->first();

            $label = TransactionApprovalRequest::TYPE_LABELS[$type] ?? $type;

            // Payload di-WHITELIST per jenis (field liar tidak ikut tersimpan)
            // dan nominal divalidasi (audit Persetujuan Transaksi 2026-09-29).
            $allowed = [
                'booking_referral' => ['transaction_amount', 'amount_received', 'referral_code', 'spend_promo_id', 'spend_promo_discount', 'voucher_claim_id', 'payment_method', 'vehicle_size'],
                'refund' => ['amount', 'reason'],
                'booking_down_payment' => ['amount', 'notes'],
            ][$type] ?? [];
            $payload = array_intersect_key($payload, array_flip($allowed));

            $amountKey = $type === 'booking_referral' ? 'transaction_amount' : 'amount';
            if (! is_numeric($payload[$amountKey] ?? null) || (float) $payload[$amountKey] < 0 || ($type !== 'booking_referral' && (float) $payload[$amountKey] <= 0)) {
                throw new RuntimeException('Nominal pengajuan tidak valid.');
            }

            if (TransactionApprovalRequest::where('booking_id', $booking->id)->where('type', $type)->where('status', 'pending')->exists()) {
                throw new RuntimeException("Sudah ada pengajuan \"{$label}\" untuk booking ini yang masih menunggu persetujuan. Tunggu keputusannya dulu.");
            }

            return TransactionApprovalRequest::create([
                'type' => $type,
                'booking_id' => $booking->id,
                'payload' => $payload,
                'status' => 'pending',
                'requested_by' => $requestedBy,
            ]);
        });
    }

    /**
     * Otorisasi & segregation of duties di SERVICE (bukan cuma visible() di
     * resource): approver harus full-access dan BUKAN pengaju pengajuan itu.
     *
     * @throws RuntimeException
     */
    private function assertMayDecide(?User $approver, TransactionApprovalRequest $request): void
    {
        if (! $approver || ! $approver->isFullAccess()) {
            throw new RuntimeException('Anda tidak berwenang memutuskan pengajuan ini.');
        }

        if ((int) $request->requested_by === (int) $approver->id) {
            throw new RuntimeException('Anda tidak boleh memutuskan pengajuan yang Anda ajukan sendiri.');
        }
    }

    /**
     * Pengaju membatalkan pengajuannya sendiri selama masih menunggu (lock +
     * recheck status). Efek samping belum terjadi (baru terjadi saat approve),
     * jadi cukup ubah status.
     *
     * @throws RuntimeException kalau bukan pengaju atau sudah diputuskan.
     */
    public function cancel(TransactionApprovalRequest $request, User $actor): void
    {
        DB::transaction(function () use ($request, $actor) {
            $locked = TransactionApprovalRequest::whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || (int) $locked->requested_by !== (int) $actor->id) {
                throw new RuntimeException('Cuma pengaju yang boleh membatalkan pengajuan ini.');
            }

            if (! $locked->isPending()) {
                throw new RuntimeException('Pengajuan ini sudah diputuskan, tidak bisa dibatalkan.');
            }

            $locked->update(['status' => 'cancelled']);
        });
    }

    /**
     * Ajukan ulang pengajuan yang DITOLAK/DIBATALKAN dengan data yang sama
     * (sekali saja, ditandai resubmitted_at) lewat jalur submit yang sama
     * sehingga validasi & guard pengajuan ganda ikut berjalan.
     *
     * @throws RuntimeException
     */
    public function resubmit(TransactionApprovalRequest $request, User $actor): TransactionApprovalRequest
    {
        if ((int) $request->requested_by !== (int) $actor->id) {
            throw new RuntimeException('Cuma pengaju asli yang boleh mengajukan ulang.');
        }

        if (! in_array($request->status, ['rejected', 'cancelled'], true)) {
            throw new RuntimeException('Cuma pengajuan yang ditolak/dibatalkan yang bisa diajukan ulang.');
        }

        if ($request->resubmitted_at) {
            throw new RuntimeException('Pengajuan ini sudah pernah diajukan ulang.');
        }

        $booking = $request->booking;
        if (! $booking) {
            throw new RuntimeException('Booking pada pengajuan ini sudah tidak ada.');
        }

        $p = $request->payload;

        $new = match ($request->type) {
            'booking_referral' => $this->submitBookingReferral(
                $booking,
                (float) ($p['transaction_amount'] ?? 0),
                isset($p['amount_received']) ? (float) $p['amount_received'] : null,
                array_diff_key($p, array_flip(['transaction_amount', 'amount_received'])),
                $actor->id
            ),
            'refund' => $this->submitRefund($booking, (float) ($p['amount'] ?? 0), $p['reason'] ?? null, $actor->id),
            'booking_down_payment' => $this->submitDownPayment($booking, (float) ($p['amount'] ?? 0), $p['notes'] ?? null, $actor->id),
            default => throw new RuntimeException('Jenis pengajuan tidak dikenal.'),
        };

        $request->update(['resubmitted_at' => now()]);

        return $new;
    }

    /**
     * approve() memakai lockForUpdate() pada baris PENGAJUAN (dan booking) di
     * dalam DB::transaction() + recheck status pada baris terkunci -- dua
     * approver yang mengklik bersamaan tidak lagi mencatat DP/referral ganda,
     * dan reject yang bertabrakan tidak bisa menimpa approve yang jurnalnya
     * sudah terposting.
     *
     * @return string[] peringatan non-fatal (mis. poin referral gagal diberikan)
     *
     * @throws RuntimeException diteruskan dari BookingPostingService/
     *         RefundService/VoucherService kalau data sudah tidak valid
     *         lagi saat akhirnya dieksekusi (mis. booking sudah diubah
     *         staff lain, atau klaim voucher yang dipilih sudah dipakai
     *         di transaksi lain, sejak permintaan diajukan).
     */
    public function approve(TransactionApprovalRequest $request, int $approvedBy): array
    {
        $approver = User::find($approvedBy);
        $warnings = [];
        $locked = null;

        DB::transaction(function () use ($request, $approver, $approvedBy, &$warnings, &$locked) {
            $locked = TransactionApprovalRequest::whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isPending()) {
                throw new RuntimeException('Permintaan ini sudah diputuskan sebelumnya.');
            }

            $this->assertMayDecide($approver, $locked);

            $booking = $locked->booking()->lockForUpdate()->firstOrFail();

            if ($locked->type === 'booking_referral') {
                $payload = $locked->payload;

                // Nominal transaksi yang sudah punya refund tidak boleh
                // ditimpa (sisa refund bisa jadi negatif) -- dicek ulang di
                // dalam lock, bukan asumsi kondisi saat diajukan.
                if ($booking->refunds()->exists()) {
                    throw new RuntimeException('Booking ini sudah punya refund, nominal transaksinya tidak bisa diubah lewat pengajuan referral. Tolak pengajuan ini.');
                }

                // Gap ditutup 2026-09-26 (audit Voucher Promo) -- klaim
                // voucher baru BENAR-BENAR ditandai "Terpakai" & dipotong
                // dari nominal DI SINI (saat approve, bukan saat request
                // diajukan) -- lihat catatan di BookingResource::process_referral.
                // Direcheck ulang di dalam lock (VoucherService::applyToBooking())
                // supaya kalau klaim ternyata sudah dipakai di transaksi
                // lain sejak staff mengajukan request ini, approval gagal
                // dengan pesan jelas alih-alih diam-diam menimpa.
                $newVoucherClaimId = $payload['voucher_claim_id'] ?? null;
                if ($booking->voucher_claim_id && $booking->voucher_claim_id !== $newVoucherClaimId) {
                    app(VoucherService::class)->releaseFromBooking($booking->voucher_claim_id);
                }
                $voucherDiscount = $newVoucherClaimId
                    ? app(VoucherService::class)->applyToBooking($newVoucherClaimId, $booking)
                    : null;

                $booking->update([
                    'transaction_amount' => $payload['transaction_amount'],
                    'amount_received' => $payload['amount_received'],
                    'referral_code' => $payload['referral_code'] ?? null,
                    'spend_promo_id' => $payload['spend_promo_id'] ?? null,
                    'spend_promo_discount' => $payload['spend_promo_discount'] ?? null,
                    'voucher_claim_id' => $newVoucherClaimId,
                    'voucher_discount' => $newVoucherClaimId ? $voucherDiscount : null,
                    'payment_method' => $payload['payment_method'] ?? null,
                    'vehicle_size' => $payload['vehicle_size'] ?? null,
                ]);

                app(BookingPostingService::class)->sync($booking->refresh());

                // Kasih poin referral SETELAH nominal ter-posting --
                // sama urutan dengan jalur full-access langsung di
                // BookingResource. Kegagalan di sini TIDAK membatalkan
                // approval (nominal/jurnal sudah sah), tapi TIDAK lagi
                // ditelan diam-diam: dilaporkan ke log/Sentry dan
                // dikembalikan sebagai peringatan (audit 2026-09-29).
                $referralService = app(ReferralPointService::class);
                if (! empty($payload['referral_code'])) {
                    try {
                        $referralService->awardForBooking($booking->fresh());
                    } catch (RuntimeException $e) {
                        report($e);
                        $warnings[] = 'Poin referral partner gagal diberikan: ' . $e->getMessage();
                    }
                }
                try {
                    $referralService->awardForCustomerReferral($booking->fresh());
                } catch (RuntimeException $e) {
                    report($e);
                    $warnings[] = 'Poin referral pelanggan gagal diberikan: ' . $e->getMessage();
                }
            } elseif ($locked->type === 'refund') {
                app(RefundService::class)->process(
                    $booking,
                    (float) $locked->payload['amount'],
                    $locked->payload['reason'] ?? null,
                    $locked->requested_by // pemohon asli tercatat sebagai created_by Refund, approver tercatat di kolom decided_by request ini
                );
            } elseif ($locked->type === 'booking_down_payment') {
                app(DownPaymentService::class)->receive(
                    $booking,
                    (float) $locked->payload['amount'],
                    $locked->payload['notes'] ?? null,
                    $locked->requested_by // sama pola dengan refund di atas -- pemohon asli tercatat sebagai created_by DP
                );
            }

            $locked->update([
                'status' => 'approved',
                'decided_by' => $approvedBy,
                'decided_at' => now(),
            ]);
        });

        $this->notifyRequesterOfDecision($locked, approved: true);

        return $warnings;
    }

    /**
     * @throws RuntimeException kalau bukan berwenang, pengaju sendiri, sudah
     *         diputuskan, atau alasan kosong (alasan penolakan WAJIB).
     */
    public function reject(TransactionApprovalRequest $request, int $approvedBy, ?string $note): void
    {
        if (blank($note)) {
            throw new RuntimeException('Alasan penolakan wajib diisi.');
        }

        $approver = User::find($approvedBy);

        $locked = DB::transaction(function () use ($request, $approver, $approvedBy, $note) {
            $locked = TransactionApprovalRequest::whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isPending()) {
                throw new RuntimeException('Permintaan ini sudah diputuskan sebelumnya.');
            }

            $this->assertMayDecide($approver, $locked);

            $locked->update([
                'status' => 'rejected',
                'decided_by' => $approvedBy,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            return $locked;
        });

        $this->notifyRequesterOfDecision($locked, approved: false);
    }

    /**
     * Pengaju diberi tahu hasil keputusan (database + push), SETELAH commit
     * dan tidak boleh menggagalkan keputusan yang sudah sah -- audit
     * Persetujuan Transaksi 2026-09-29 (sebelumnya pengaju tidak pernah tahu).
     */
    private function notifyRequesterOfDecision(TransactionApprovalRequest $request, bool $approved): void
    {
        try {
            $requester = User::find($request->requested_by);
            if (! $requester) {
                return;
            }

            $label = TransactionApprovalRequest::TYPE_LABELS[$request->type] ?? $request->type;
            $booking = $request->booking?->booking_number ?? '—';
            $title = $approved ? "{$label} disetujui" : "{$label} ditolak";
            $body = "Booking {$booking} — Rp" . number_format($request->amount(), 0, ',', '.')
                . ($approved ? ' sudah diposting.' : ' ditolak: ' . $request->decision_note);

            $notification = Notification::make()->title($title)->body($body);
            $approved ? $notification->success() : $notification->danger();
            $notification->sendToDatabase($requester);

            app(PushNotificationService::class)->sendToUsers([$requester->id], $title, $body);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Pengaju diberi tahu pengajuannya TERTAHAN saat approver mencoba
     * menyetujui tapi gagal (mis. periode ditutup, refund melebihi sisa).
     */
    public function notifyRequesterStuck(TransactionApprovalRequest $request, string $reason): void
    {
        try {
            if ($requester = User::find($request->requested_by)) {
                Notification::make()
                    ->title('Pengajuan Anda tertahan')
                    ->body(($request->booking?->booking_number ?? '') . ': ' . $reason)
                    ->warning()
                    ->sendToDatabase($requester);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notifyFullAccess(Booking $booking, TransactionApprovalRequest $request): void
    {
        $label = TransactionApprovalRequest::TYPE_LABELS[$request->type] ?? $request->type;
        $recipients = User::where('is_active', true)->where('id', '!=', $request->requested_by)->get()->filter(fn (User $u) => $u->isFullAccess());

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title("Menunggu persetujuan: {$label}")
                ->body("Booking {$booking->booking_number} — perlu persetujuan Anda sebelum diposting ke Jurnal Umum.")
                ->warning()
                ->sendToDatabase($recipient);
        }

        // Push ke approver (sebelumnya cuma notifikasi database); kegagalan
        // kirim tidak boleh menggagalkan pengajuan.
        try {
            app(PushNotificationService::class)->sendToUsers(
                $recipients->pluck('id'),
                "Menunggu persetujuan: {$label}",
                "Booking {$booking->booking_number} — perlu persetujuan Anda."
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
