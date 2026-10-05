<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingCancellationRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Keputusan staff atas pengajuan pembatalan booking confirmed dari customer
 * (2026-10-05). Persetujuan membatalkan booking lewat Booking::cancelWith()
 * (alasan customer jadi cancel_reason) dan mengembalikan DP penuh -- aturan
 * yang sama dengan jalur pembatalan lain.
 */
class BookingCancellationService
{
    public function __construct(private PushNotificationService $push)
    {
    }

    /** @throws RuntimeException pesan siap tampil ke pengguna. */
    public function approve(BookingCancellationRequest $request, int $staffId, ?string $note): Booking
    {
        return DB::transaction(function () use ($request, $staffId, $note) {
            // Urutan lock booking -> pengajuan (sama dengan jalur lain).
            $booking = Booking::where('id', $request->booking_id)->lockForUpdate()->first();
            $lockedRequest = BookingCancellationRequest::where('id', $request->id)->lockForUpdate()->first();

            if ($lockedRequest->status !== 'pending') {
                throw new RuntimeException('Pengajuan ini sudah diputuskan sebelumnya.');
            }

            if (! in_array($booking->status, ['pending', 'confirmed'], true)) {
                throw new RuntimeException("Booking berstatus \"{$booking->status}\" tidak bisa dibatalkan.");
            }

            // Pengajuan ditandai disetujui DULU supaya BookingObserver (yang
            // menutup pengajuan menggantung saat booking final) tidak
            // menimpanya jadi "ditolak".
            $lockedRequest->update([
                'status'        => 'approved',
                'decided_by'    => $staffId,
                'decided_at'    => now(),
                'decision_note' => $note,
            ]);

            $booking->cancelWith('customer', $lockedRequest->customer_id, $lockedRequest->reason);

            app(DownPaymentService::class)->refundAllOnCancellation($booking->id, $staffId);

            return $booking->fresh();
        });
    }

    /** @throws RuntimeException */
    public function reject(BookingCancellationRequest $request, int $staffId, string $note): void
    {
        DB::transaction(function () use ($request, $staffId, $note) {
            Booking::whereKey($request->booking_id)->lockForUpdate()->first();
            $lockedRequest = BookingCancellationRequest::where('id', $request->id)->lockForUpdate()->first();

            if ($lockedRequest->status !== 'pending') {
                throw new RuntimeException('Pengajuan ini sudah diputuskan sebelumnya.');
            }

            $lockedRequest->update([
                'status'        => 'rejected',
                'decided_by'    => $staffId,
                'decided_at'    => now(),
                'decision_note' => $note,
            ]);
        });

        $booking = $request->booking;
        if ($booking?->customer_id) {
            $customerId = $booking->customer_id;
            $number = $booking->booking_number;
            $bookingId = $booking->id;
            DB::afterCommit(fn () => $this->push->sendToCustomer(
                $customerId,
                'Pengajuan Pembatalan Ditolak',
                "Pengajuan pembatalan booking #{$number} ditolak: {$note}",
                ['type' => 'booking_cancellation_rejected', 'booking_id' => $bookingId, 'route' => "/booking/{$bookingId}/chat"]
            ));
        }
    }
}
