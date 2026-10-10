<?php

namespace App\Observers;

use App\Filament\Resources\BookingResource;
use App\Mail\NewBookingMail;
use App\Models\Booking;
use App\Models\User;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BookingObserver
{
    public function __construct(private PushNotificationService $push)
    {
    }

    /**
     * Kirim notifikasi email + push ke staff toko yang punya AKSES MENU
     * BOOKING (hasBookingAccess(), bukan sekadar isRestrictedStaff() —
     * diperbaiki 2026-10-01, sebelumnya staff toko tanpa akses menu
     * Booking tetap kebanjiran notif ini) yang terkait saat ada booking
     * baru masuk — baik dari app, WhatsApp manual, maupun walk-in yang
     * diinput admin sendiri.
     *
     * Kalau toko tersebut belum punya staff ber-akses Booking terdaftar,
     * fallback kirim ke semua yang isFullAccess() (super_admin/direksi)
     * supaya booking tidak pernah terlewat tanpa notif.
     */
    /**
     * Sinkronkan current_stage saat booking ditandai 'completed' dari jalur
     * MANA PUN (diperbaiki 2026-10-02, audit alur Booking) -- jalur mobile
     * complete() sudah mengisinya, tapi form Edit Filament tidak, jadi
     * banner ulasan di chat customer dan kartu progres beranda (yang
     * membaca current_stage) tidak konsisten antar jalur.
     */
    public function updating(Booking $booking): void
    {
        if ($booking->isDirty('status') && $booking->status === 'completed' && $booking->current_stage !== 'completed') {
            $booking->current_stage = 'completed';
        }
    }

    public function created(Booking $booking): void
    {
        $staff = User::where('store_id', $booking->store_id)
            ->get()
            ->filter(fn (User $u) => $u->isRestrictedStaff() && $u->hasBookingAccess());

        $recipients = $staff->pluck('email');

        if ($recipients->isEmpty()) {
            $recipients = User::role(['super_admin', 'direksi'])->pluck('email');
        }

        if ($recipients->isNotEmpty()) {
            try {
                Mail::to($recipients->all())->send(new NewBookingMail($booking));
            } catch (\Exception $e) {
                // Jangan sampai kegagalan kirim email menggagalkan proses
                // pembuatan booking itu sendiri — cukup dicatat di log +
                // dilaporkan ke Sentry (audit framework 2026-09-14,
                // "Monitoring & alerting error production").
                Log::error('Gagal mengirim notifikasi email booking baru', [
                    'booking_id' => $booking->id,
                    'error'      => $e->getMessage(),
                ]);
                report($e);
            }
        }

        if ($booking->store_id) {
            $this->push->sendToStoreStaff(
                $booking->store_id,
                'Booking Baru',
                "Booking baru #{$booking->booking_number} masuk untuk {$booking->service_type}.",
                [
                    'type'       => 'booking_new',
                    'booking_id' => $booking->id,
                    'route'      => "/staff/bookings/{$booking->id}",
                ],
                BookingResource::class,
            );
        }
    }

    /**
     * Customer SEBELUMNYA tidak pernah diberi tahu sama sekali begitu
     * booking-nya di-approve/dibatalkan — cuma tahu kalau buka app manual,
     * atau kebetulan staff sudah mulai kirim pesan tahap pertama (yang
     * baru memicu notif lewat BookingMessageObserver). Sekarang perubahan
     * status itu sendiri yang memicu notif, terlepas dari ada pesan chat
     * atau tidak. Cuma peduli booking dari app (customer_id terisi) —
     * booking walk-in/WA manual tanpa akun customer tidak punya siapa pun
     * untuk dikirimi notif.
     */
    public function updated(Booking $booking): void
    {
        // Bagian C, "Klaim Garansi & Maintenance PPF" (2026-10-01) -- di
        // ATAS guard customer_id di bawah SENGAJA (tidak bergantung customer
        // punya akun app, murni soal warranty_id terisi atau tidak). Titik
        // tunggal untuk SEMUA jalur yang bisa menandai booking 'completed'
        // (mobile staff BookingController::complete(), maupun lewat form
        // edit Filament) -- daripada menambal tiap endpoint satu-satu.
        if ($booking->wasChanged('status') && $booking->status === 'completed' && $booking->warranty_id) {
            $schedule = $booking->maintenanceSchedule;

            if ($schedule) {
                $schedule->completeAndScheduleNext();
            }

            // Ledger historis (warranty_maintenance_visits) TETAP terpisah
            // dari WarrantyMaintenanceSchedule (status occurrence) -- lihat
            // catatan di WarrantyMaintenanceVisit. Dicatat di sini supaya
            // kunjungan yang lahir dari alur konfirmasi app ikut tercatat
            // sama seperti kunjungan walk-in yang dicatat manual staff lewat
            // WarrantyResource::performRecordMaintenanceVisit().
            $visit = \App\Models\WarrantyMaintenanceVisit::create([
                'warranty_id' => $booking->warranty_id,
                'visited_at'  => $booking->preferred_date ?? today(),
                'note'        => "Maintenance via booking #{$booking->booking_number}",
            ]);

            // Poin kunjungan maintenance (0 = nonaktif, lihat config/loyalty.php). Kegagalan poin tidak boleh
            // menggagalkan penyelesaian booking.
            try {
                app(\App\Services\MaintenanceVisitPointService::class)->award($visit);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Bug ditutup 2026-10-01 (audit Maintenance PPF) -- SEBELUMNYA kalau
        // booking Maintenance PPF di-cancel (bukan completed), occurrence
        // jadwalnya (status 'confirmed', booking_id sudah terisi) tidak
        // pernah ditangani sama sekali -- menggantung permanen, tidak
        // pernah lanjut ke occurrence berikutnya ATAU bisa dikonfirmasi
        // ulang. Dikembalikan ke 'pending' (BUKAN forfeit/lanjut ke sequence
        // berikutnya -- itu keputusan produk terpisah, lihat audit) supaya
        // occurrence ini aktif lagi & diproses natural oleh
        // ProcessMaintenanceSchedules (dapat konfirmasi ulang kalau
        // scheduled_date masih di masa depan, atau forfeit otomatis kalau
        // sudah lewat tanggal).
        // Pengajuan jadwal ulang yang masih menunggu ditutup begitu booking
        // final (diperbaiki 2026-10-02) -- sebelumnya menggantung selamanya
        // dan kartu customer tetap "menunggu keputusan toko".
        if ($booking->wasChanged('status') && in_array($booking->status, ['cancelled', 'completed'], true)) {
            $closedCount = $booking->rescheduleRequests()->where('status', 'pending')->count()
                + $booking->cancellationRequests()->where('status', 'pending')->count();
            $booking->rescheduleRequests()->where('status', 'pending')->update([
                'status'        => 'rejected',
                'decided_at'    => now(),
                'decision_note' => $booking->status === 'cancelled' ? 'Booking dibatalkan.' : 'Booking sudah selesai.',
            ]);
            // Pengajuan pembatalan yang menggantung juga ditutup (2026-10-05).
            $booking->cancellationRequests()->where('status', 'pending')->update([
                'status'        => 'rejected',
                'decided_at'    => now(),
                'decision_note' => $booking->status === 'cancelled' ? 'Booking sudah dibatalkan.' : 'Booking sudah selesai.',
            ]);
        }

        // Customer diberi tahu kalau pengajuannya ditutup otomatis oleh
        // keputusan lain (mis. booking diselesaikan staff) -- kecuali ia
        // sendiri yang membatalkan, karena ia sudah tahu.
        if (($closedCount ?? 0) > 0 && $booking->customer_id && $booking->cancelled_by_type !== 'customer') {
            $closedBody = $booking->status === 'cancelled'
                ? "Pengajuan Anda untuk booking #{$booking->booking_number} ditutup karena booking sudah dibatalkan."
                : "Pengajuan Anda untuk booking #{$booking->booking_number} ditutup karena booking sudah selesai.";
            $closedData = ['type' => 'booking_request_closed', 'booking_id' => $booking->id, 'route' => "/booking/{$booking->id}/chat"];
            DB::afterCommit(fn () => $this->push->sendToCustomer($booking->customer_id, 'Pengajuan Ditutup', $closedBody, $closedData));
        }

        // Dikonfirmasi / dipindah ke "BESOK" setelah jam 16:00: tidak ada run
        // pengingat terjadwal lagi sebelum besok, jadi H-1 dikirim sekarang.
        if ($booking->status === 'confirmed'
            && ($booking->wasChanged('status') || $booking->wasChanged('preferred_date'))
            && $booking->preferred_date?->isSameDay(today()->addDay())
            && now()->hour >= 16) {
            $h1Booking = $booking->fresh();
            DB::afterCommit(fn () => app(\App\Services\BookingReminderService::class)->sendH1($h1Booking));
        }

        // Installer yang dibatalkan jadwalnya (booking confirmed -> cancelled)
        // diberi tahu supaya tidak datang sia-sia.
        if ($booking->wasChanged('status') && $booking->status === 'cancelled' && $booking->getOriginal('status') === 'confirmed') {
            $cancelInstallerIds = $booking->installers()->pluck('users.id');
            if ($cancelInstallerIds->isNotEmpty()) {
                $cancelBody = "Booking #{$booking->booking_number} ({$booking->preferred_date?->format('d M Y')}) dibatalkan.";
                $cancelData = ['type' => 'booking_cancelled', 'booking_id' => $booking->id, 'route' => "/staff/bookings/{$booking->id}"];
                DB::afterCommit(fn () => $this->push->sendToUsers($cancelInstallerIds, 'Booking Dibatalkan', $cancelBody, $cancelData));
            }
        }

        // Installer yang ditugaskan diberi tahu kalau jadwal booking
        // confirmed berubah (2026-10-02).
        if ($booking->wasChanged('preferred_date') && $booking->status === 'confirmed') {
            $installerIds = $booking->installers()->pluck('users.id');
            if ($installerIds->isNotEmpty()) {
                $body = "Booking #{$booking->booking_number} dipindah ke {$booking->preferred_date?->format('d M Y')}.";
                $data = ['type' => 'booking_rescheduled', 'booking_id' => $booking->id, 'route' => "/staff/bookings/{$booking->id}"];
                DB::afterCommit(fn () => $this->push->sendToUsers($installerIds, 'Jadwal Instalasi Berubah', $body, $data));
            }
        }

        // Booking Selesai dari jalur MANA PUN (mobile maupun form Filament)
        // tercatat di timeline chat sebagai tahap "completed" -- pesan itu
        // sendiri yang memicu push ke customer (BookingMessageObserver).
        // Dibuat setelah commit & hanya kalau belum ada (2026-10-03).
        if ($booking->wasChanged('status') && $booking->status === 'completed') {
            $bookingId = $booking->id;
            $actorId = auth()->id();
            DB::afterCommit(function () use ($bookingId, $actorId) {
                $exists = \App\Models\BookingMessage::where('booking_id', $bookingId)
                    ->where('type', 'stage')->where('stage', 'completed')->exists();
                if (! $exists) {
                    \App\Models\BookingMessage::create([
                        'booking_id'     => $bookingId,
                        'sender_type'    => 'admin',
                        'sender_user_id' => $actorId,
                        'type'           => 'stage',
                        'stage'          => 'completed',
                    ]);
                }
            });
        }

        if ($booking->wasChanged('status') && $booking->status === 'cancelled' && $booking->warranty_id) {
            $schedule = $booking->maintenanceSchedule;

            if ($schedule && $schedule->status === 'confirmed') {
                $schedule->update(['status' => 'pending', 'booking_id' => null, 'responded_at' => null]);
            }
        }

        if (! $booking->customer_id) {
            return;
        }

        $tanggal = $booking->preferred_date?->format('d M Y');

        // Semua push ke customer dikirim SETELAH commit (2026-10-03) --
        // sebelumnya terkirim di dalam transaksi, jadi kalau langkah
        // sesudahnya gagal (mis. refund DP) customer sudah terlanjur diberi
        // tahu hal yang tidak terjadi.
        if ($booking->wasChanged('status')) {
            $status = $booking->status;
            // Pembatalan lewat pengajuan customer yang disetujui toko: kalimat
            // yang menjelaskan hal itu (bukan sekadar "dibatalkan").
            $approvedRequest = $status === 'cancelled'
                && $booking->cancellationRequests()->where('status', 'approved')->exists();
            DB::afterCommit(fn () => match ($status) {
                'confirmed' => $this->push->sendToCustomer(
                    $booking->customer_id,
                    'Booking Dikonfirmasi',
                    "Booking #{$booking->booking_number} Anda sudah dikonfirmasi toko untuk tanggal {$tanggal}.",
                    [
                        'type'       => 'booking_confirmed',
                        'booking_id' => $booking->id,
                        'route'      => "/booking/{$booking->id}/chat",
                    ]
                ),
                'cancelled' => $this->push->sendToCustomer(
                    $booking->customer_id,
                    'Booking Dibatalkan',
                    $approvedRequest
                        ? "Pengajuan pembatalan booking #{$booking->booking_number} ({$tanggal}) disetujui toko. Booking dibatalkan; DP (jika ada) dikembalikan penuh."
                        : "Booking #{$booking->booking_number} Anda ({$tanggal}) telah dibatalkan"
                            . ($booking->cancel_reason ? ": {$booking->cancel_reason}" : '.')
                            . ' Hubungi toko untuk info lebih lanjut.',
                    [
                        'type'       => 'booking_cancelled',
                        'booking_id' => $booking->id,
                        'route'      => "/booking/{$booking->id}/chat",
                    ]
                ),
                default => null,
            });
        }

        // Reschedule (audit modul Booking Instalasi 2026-09-25, gap
        // "standar enterprise"): SEBELUMNYA mengubah preferred_date pada
        // booking yang sudah 'confirmed' TIDAK memicu notifikasi apa pun
        // — customer cuma tahu kalau kebetulan buka app atau dihubungi
        // manual. 'confirmed' saja yang relevan diberi tahu — booking
        // 'pending' belum pasti tanggalnya di mata customer (bisa masih
        // berubah saat triase), dan 'completed'/'cancelled' sudah final
        // (tidak masuk akal notif reschedule utk booking yang sudah
        // kelar/batal). SENGAJA method notifikasi TERPISAH (bukan ditumpuk
        // ke match status di atas) — status TIDAK berubah di sini, cuma
        // tanggalnya, jadi keduanya independen dan bisa terjadi sekaligus
        // dalam 1 update (mis. staff ganti tanggal SEKALIGUS approve).
        if ($booking->wasChanged('preferred_date') && $booking->status === 'confirmed') {
            $tanggalLama = $booking->getOriginal('preferred_date');
            $tanggalLama = $tanggalLama ? \Illuminate\Support\Carbon::parse($tanggalLama)->format('d M Y') : null;
            $alasanPindah = $booking->rescheduleReason ? " Alasan: {$booking->rescheduleReason}" : '';

            DB::afterCommit(fn () => $this->push->sendToCustomer(
                $booking->customer_id,
                'Jadwal Booking Berubah',
                ($tanggalLama
                    ? "Booking #{$booking->booking_number} Anda dijadwal ulang dari {$tanggalLama} ke {$tanggal}."
                    : "Booking #{$booking->booking_number} Anda dijadwal ulang ke {$tanggal}.") . $alasanPindah,
                [
                    'type'       => 'booking_rescheduled',
                    'booking_id' => $booking->id,
                    'route'      => "/booking/{$booking->id}/chat",
                ]
            ));
        }
    }
}
