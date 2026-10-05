<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingRescheduleRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ganti tanggal booking (keputusan user 2026-10-02: staff DAN customer sama-
 * sama bisa -- staff langsung dari app, customer mengajukan lalu staff
 * menyetujui). Satu tempat untuk aturan tanggal & kapasitas, dipakai
 * endpoint staff (langsung) dan persetujuan pengajuan customer.
 */
class BookingRescheduleService
{
    public function __construct(private PushNotificationService $push)
    {
    }

    /**
     * Terapkan tanggal baru. Booking pending: tanggal tidak boleh penuh
     * (hari mulai); booking confirmed: SELURUH durasi dicek ulang dengan lock
     * toko (slot lama dilepas otomatis, booking ini dikecualikan dari
     * hitungan). Toko tutup/diblokir & tanggal lampau ditolak.
     *
     * @throws RuntimeException pesan siap tampil ke pengguna.
     */
    public function apply(Booking $booking, Carbon $newDate, ?int $actorId = null, ?int $keepRequestId = null, ?string $reason = null): Booking
    {
        return DB::transaction(function () use ($booking, $newDate, $actorId, $keepRequestId, $reason) {
            $locked = Booking::where('id', $booking->id)->lockForUpdate()->first();

            if (! in_array($locked->status, ['pending', 'confirmed'], true)) {
                throw new RuntimeException("Booking berstatus \"{$locked->status}\" tidak bisa dijadwal ulang.");
            }

            // Pengerjaan yang sudah dimulai tidak dipindah tanggal
            // (2026-10-02) -- unit sudah di toko / slot sudah terpakai.
            if ($locked->hasWorkStarted()) {
                throw new RuntimeException('Pengerjaan booking ini sudah dimulai, jadwalnya tidak bisa diubah lagi.');
            }

            $newDate = $newDate->copy()->startOfDay();

            if ($locked->preferred_date && $locked->preferred_date->toDateString() === $newDate->toDateString()) {
                throw new RuntimeException('Tanggal baru sama dengan jadwal sekarang.');
            }

            if ($newDate->lt(today())) {
                throw new RuntimeException('Tanggal baru tidak boleh sebelum hari ini.');
            }

            if ($locked->store?->isClosedOn($newDate)) {
                throw new RuntimeException('Toko tutup/libur atau tanggal diblokir pada tanggal itu. Pilih tanggal lain.');
            }

            if ($locked->status === 'confirmed') {
                $full = Booking::fullDatesInRangeLocked(
                    (int) $locked->store_id,
                    $newDate,
                    (int) ($locked->duration_days ?: 1),
                    excludeBookingId: $locked->id,
                );

                if (! empty($full)) {
                    throw new RuntimeException('Kapasitas toko penuh di tanggal: ' . implode(', ', $full) . '. Pilih tanggal lain.');
                }
            } else {
                $used = Booking::confirmedOverlapCount((int) $locked->store_id, $newDate);
                if ($used >= Booking::capacityForDate((int) $locked->store_id, $newDate)) {
                    throw new RuntimeException('Kapasitas toko penuh di tanggal itu. Pilih tanggal lain.');
                }
            }

            $old = $locked->preferred_date;
            $locked->rescheduleReason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
            // Penanda pengingat H-1 direset: tanggal baru perlu diingatkan lagi.
            $locked->forceFill(['preferred_date' => $newDate->toDateString(), 'h1_reminder_sent_at' => null])->save();


            // Booking Maintenance PPF: tanggal jadwal garansinya ikut pindah
            // supaya tidak ada dua tanggal berbeda (2026-10-02).
            $locked->maintenanceSchedule?->update(['scheduled_date' => $newDate->toDateString()]);

            // Pengajuan customer lain yang masih menunggu jadi tidak relevan
            // -- ditutup di transaksi yang SAMA (diperbaiki 2026-10-02). Customer
            // tetap tahu lewat push "Jadwal Booking Berubah".
            $locked->rescheduleRequests()
                ->where('status', 'pending')
                ->when($keepRequestId, fn ($q) => $q->where('id', '!=', $keepRequestId))
                ->update([
                    'status'        => 'rejected',
                    'decided_by'    => $actorId,
                    'decided_at'    => now(),
                    'decision_note' => 'Jadwal sudah diubah oleh toko.',
                ]);

            // Booking confirmed: BookingObserver sudah mengirim push "Jadwal
            // Booking Berubah". Booking pending tidak dicakup observer
            // (tanggalnya belum pasti di mata customer), jadi dikirim di sini
            // supaya customer tetap tahu kalau STAFF yang mengubahnya.
            // Dikirim SETELAH commit (diperbaiki 2026-10-02) -- apply() bisa
            // dipanggil di dalam approve() yang masih bisa rollback.
            if ($locked->status === 'pending' && $locked->customer_id && $old?->toDateString() !== $newDate->toDateString()) {
                $customerId = $locked->customer_id;
                $title = "Booking #{$locked->booking_number} dijadwal ulang ke {$newDate->format('d M Y')}."
                    . ($locked->rescheduleReason ? " Alasan: {$locked->rescheduleReason}" : '');
                $bookingId = $locked->id;
                DB::afterCommit(fn () => $this->push->sendToCustomer(
                    $customerId,
                    'Jadwal Booking Berubah',
                    $title,
                    ['type' => 'booking_rescheduled', 'booking_id' => $bookingId, 'route' => "/booking/{$bookingId}/chat"]
                ));
            }

            return $locked->fresh();
        });
    }

    /** Setujui pengajuan customer: terapkan tanggal lalu tandai disetujui. */
    public function approve(BookingRescheduleRequest $request, int $staffId, ?string $note): Booking
    {
        return DB::transaction(function () use ($request, $staffId, $note) {
            // Urutan lock booking -> pengajuan, sama dengan jalur batal/selesai
            // (observer menutup pengajuan setelah booking terkunci) supaya
            // tidak deadlock (2026-10-03).
            Booking::whereKey($request->booking_id)->lockForUpdate()->first();
            $lockedRequest = BookingRescheduleRequest::where('id', $request->id)->lockForUpdate()->first();

            if ($lockedRequest->status !== 'pending') {
                throw new RuntimeException('Pengajuan ini sudah diputuskan sebelumnya.');
            }

            $booking = $this->apply($lockedRequest->booking, $lockedRequest->requested_date, $staffId, $lockedRequest->id);

            $lockedRequest->update([
                'status'        => 'approved',
                'decided_by'    => $staffId,
                'decided_at'    => now(),
                'decision_note' => $note,
            ]);

            return $booking;
        });
    }

    /** Tolak pengajuan customer + beri tahu alasannya. */
    public function reject(BookingRescheduleRequest $request, int $staffId, ?string $note): void
    {
        DB::transaction(function () use ($request, $staffId, $note) {
            Booking::whereKey($request->booking_id)->lockForUpdate()->first();
            $lockedRequest = BookingRescheduleRequest::where('id', $request->id)->lockForUpdate()->first();

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
            $this->push->sendToCustomer(
                $booking->customer_id,
                'Pengajuan Jadwal Ulang Ditolak',
                "Pengajuan jadwal ulang booking #{$booking->booking_number} ditolak" . ($note ? ": {$note}" : '.'),
                ['type' => 'booking_reschedule_rejected', 'booking_id' => $booking->id, 'route' => "/booking/{$booking->id}/chat"]
            );
        }
    }
}
