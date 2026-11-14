<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Booking;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pengajuan Lembur (2026-11-12, keputusan pemilik): lembur wajib diajukan SEBELUM dimulai, dengan pekerjaan yang jelas (tautan Booking atau uraian
 * tertulis), dan disetujui store manager toko itu atau akses penuh. Hanya jam yang disetujui yang diakui sebagai lembur; jam kerja di luar jam resmi
 * tanpa persetujuan tidak diakui dan ditandai di rekap absensi. Upah lembur tetap dihitung di luar sistem.
 *
 * Aturan yang sama dipakai aplikasi staf dan panel. Pola persetujuan mengikuti AttendanceCorrectionService (kunci baris di dalam transaksi,
 * pemohon tidak boleh memutuskan pengajuannya sendiri).
 */
class OvertimeRequestService
{
    public const MIN_MINUTES = 30;

    /** Batas hukum lembur (PP 35/2021): 4 jam per hari dan 18 jam per minggu, dihitung dari pengajuan yang menunggu dan yang disetujui. */
    public const MAX_MINUTES_PER_DAY = 240;

    public const MAX_MINUTES_PER_WEEK = 1080;

    /** Pengajuan paling jauh sekian hari ke depan. */
    public const MAX_DAYS_AHEAD = 14;

    /** Kelebihan jam di bawah ini setelah jam tutup tidak ditandai "tanpa persetujuan" (wajar pulang beberapa menit setelah tutup). */
    public const UNAPPROVED_GRACE_MINUTES = 15;

    /**
     * @param array{starts_at: Carbon|string, ends_at: Carbon|string, booking_id?: ?int, description?: ?string} $data
     *
     * @throws RuntimeException bila pengajuan tidak memenuhi aturan.
     */
    public function submit(User $user, array $data): OvertimeRequest
    {
        $startsAt = Carbon::parse($data['starts_at']);
        $endsAt = Carbon::parse($data['ends_at']);
        $bookingId = ! empty($data['booking_id']) ? (int) $data['booking_id'] : null;
        $description = isset($data['description']) ? trim((string) $data['description']) : null;
        $description = $description === '' ? null : $description;

        $this->assertValid($user, $startsAt, $endsAt, $bookingId, $description);

        $request = OvertimeRequest::create([
            'user_id' => $user->id,
            'store_id' => $user->store_id,
            'booking_id' => $bookingId,
            'date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'minutes' => (int) $startsAt->diffInMinutes($endsAt),
            'description' => $description,
            'status' => OvertimeRequest::STATUS_PENDING,
        ]);

        $this->notifyApprovers($request);

        return $request;
    }

    private function assertValid(User $user, Carbon $startsAt, Carbon $endsAt, ?int $bookingId, ?string $description): void
    {
        if ($user->isAttendanceExempt()) {
            throw new RuntimeException('Akun ini tidak wajib absen sehingga tidak mengajukan lembur.');
        }

        if (! $user->store_id) {
            throw new RuntimeException('Akun ini belum terhubung ke toko mana pun, tidak bisa mengajukan lembur.');
        }

        if ($startsAt->lessThanOrEqualTo(now())) {
            throw new RuntimeException('Lembur harus diajukan sebelum dimulai. Jam mulai harus di waktu yang akan datang.');
        }

        if ($startsAt->greaterThan(now()->addDays(self::MAX_DAYS_AHEAD)->endOfDay())) {
            throw new RuntimeException('Lembur paling jauh diajukan ' . self::MAX_DAYS_AHEAD . ' hari ke depan.');
        }

        if ($endsAt->toDateString() !== $startsAt->toDateString()) {
            throw new RuntimeException('Lembur harus selesai di hari yang sama dengan jam mulainya. Ajukan terpisah untuk hari berikutnya.');
        }

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new RuntimeException('Jam selesai harus setelah jam mulai.');
        }

        $minutes = (int) $startsAt->diffInMinutes($endsAt);

        if ($minutes < self::MIN_MINUTES) {
            throw new RuntimeException('Lembur minimal ' . self::MIN_MINUTES . ' menit.');
        }

        // Pekerjaan yang jelas: tautan Booking atau uraian tertulis (boleh salah satu).
        if ($bookingId) {
            $booking = Booking::withoutGlobalScopes()->find($bookingId);

            if (! $booking || (int) $booking->store_id !== (int) $user->store_id) {
                throw new RuntimeException('Booking yang dipilih tidak ditemukan di toko ini.');
            }
        } elseif ($description === null) {
            throw new RuntimeException('Jelaskan pekerjaan yang akan dilembur, atau pilih Booking yang terkait.');
        }

        $date = $startsAt->toDateString();

        $onLeave = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();

        if ($onLeave) {
            throw new RuntimeException('Pada tanggal itu sedang izin/cuti, tidak bisa mengajukan lembur.');
        }

        $active = OvertimeRequest::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereIn('status', [OvertimeRequest::STATUS_PENDING, OvertimeRequest::STATUS_APPROVED]);

        $overlaps = (clone $active)->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)->exists();

        if ($overlaps) {
            throw new RuntimeException('Jam ini bertabrakan dengan pengajuan lembur lain.');
        }

        $dayMinutes = (int) (clone $active)->whereDate('date', $date)->sum('minutes');

        if ($dayMinutes + $minutes > self::MAX_MINUTES_PER_DAY) {
            throw new RuntimeException('Lembur paling banyak ' . (self::MAX_MINUTES_PER_DAY / 60) . ' jam per hari. Pengajuan pada tanggal itu sudah ' . $this->hours($dayMinutes) . ' jam.');
        }

        $weekMinutes = (int) (clone $active)
            ->whereBetween('date', [$startsAt->copy()->startOfWeek()->toDateString(), $startsAt->copy()->endOfWeek()->toDateString()])
            ->sum('minutes');

        if ($weekMinutes + $minutes > self::MAX_MINUTES_PER_WEEK) {
            throw new RuntimeException('Lembur paling banyak ' . (self::MAX_MINUTES_PER_WEEK / 60) . ' jam per minggu. Pengajuan pada minggu itu sudah ' . $this->hours($weekMinutes) . ' jam.');
        }
    }

    public function approve(OvertimeRequest $request, User $by, ?string $notes = null): void
    {
        DB::transaction(function () use ($request, $by, $notes) {
            $locked = $this->lockPending($request);
            $this->assertCanDecide($locked, $by);

            if ($locked->ends_at->lessThan(now())) {
                throw new RuntimeException('Jam lembur ini sudah lewat, tidak bisa disetujui lagi. Lembur harus disetujui sebelum dikerjakan.');
            }

            $locked->update([
                'status' => OvertimeRequest::STATUS_APPROVED,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);

            $this->notifyRequester($locked, approved: true);
        });
    }

    public function reject(OvertimeRequest $request, User $by, string $notes): void
    {
        if (trim($notes) === '') {
            throw new RuntimeException('Alasan penolakan wajib diisi.');
        }

        DB::transaction(function () use ($request, $by, $notes) {
            $locked = $this->lockPending($request);
            $this->assertCanDecide($locked, $by);

            $locked->update([
                'status' => OvertimeRequest::STATUS_REJECTED,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'review_notes' => trim($notes),
            ]);

            $this->notifyRequester($locked, approved: false);
        });
    }

    /** Pemohon menarik pengajuannya: selama masih menunggu, atau sudah disetujui tetapi belum dimulai. */
    public function cancel(OvertimeRequest $request, int $userId): void
    {
        DB::transaction(function () use ($request, $userId) {
            $locked = OvertimeRequest::withoutGlobalScopes()->whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || $locked->user_id !== $userId) {
                throw new RuntimeException('Pengajuan ini bukan milik akun ini.');
            }

            $cancellable = $locked->isPending() || ($locked->isApproved() && $locked->starts_at->greaterThan(now()));

            if (! $cancellable) {
                throw new RuntimeException('Pengajuan ini tidak bisa dibatalkan: sudah diputuskan, ditolak, atau lembur sudah dimulai.');
            }

            $locked->update(['status' => OvertimeRequest::STATUS_CANCELLED]);
        });
    }

    private function lockPending(OvertimeRequest $request): OvertimeRequest
    {
        $locked = OvertimeRequest::withoutGlobalScopes()->whereKey($request->id)->lockForUpdate()->first();

        if (! $locked || ! $locked->isPending()) {
            throw new RuntimeException('Pengajuan ini sudah diputuskan sebelumnya.');
        }

        return $locked;
    }

    /** Boleh memutuskan? Akses penuh, atau store manager toko pengajuan itu; tidak pernah pengajuan sendiri. Dipakai Pusat Persetujuan. */
    public function canDecide(User $by, OvertimeRequest $request): bool
    {
        if ($request->user_id === $by->id) {
            return false;
        }

        return $by->isFullAccess() || ($by->isStoreManager() && (int) $by->store_id === (int) $request->store_id);
    }

    /** Pemutus: akses penuh, atau store manager toko pengajuan itu; tidak boleh memutuskan pengajuan sendiri. */
    private function assertCanDecide(OvertimeRequest $request, User $by): void
    {
        if ($request->user_id === $by->id) {
            throw new RuntimeException('Tidak boleh memutuskan pengajuan lembur milik sendiri.');
        }

        $allowed = $by->isFullAccess() || ($by->isStoreManager() && (int) $by->store_id === (int) $request->store_id);

        if (! $allowed) {
            throw new RuntimeException('Hanya akses penuh atau store manager toko ini yang boleh memutuskan pengajuan lembur.');
        }
    }

    // ------------------------------------------------------------------ jam lembur yang diakui

    /** Pengajuan lembur yang disetujui untuk satu karyawan pada satu tanggal. */
    public function approvedFor(int $userId, Carbon|string $date): Collection
    {
        return OvertimeRequest::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('status', OvertimeRequest::STATUS_APPROVED)
            ->whereDate('date', Carbon::parse($date)->toDateString())
            ->get();
    }

    /** Irisan dua rentang waktu, dalam menit (0 bila tidak beririsan). */
    public static function overlapMinutes(Carbon $aStart, Carbon $aEnd, Carbon $bStart, Carbon $bEnd): int
    {
        $start = $aStart->greaterThan($bStart) ? $aStart : $bStart;
        $end = $aEnd->lessThan($bEnd) ? $aEnd : $bEnd;

        return $end->greaterThan($start) ? (int) $start->diffInMinutes($end) : 0;
    }

    /**
     * Menit lembur yang diakui pada satu baris absensi: irisan jam kerja nyata (absen masuk sampai keluar) dengan jam lembur yang disetujui.
     * Baris tanpa jam keluar belum bisa dihitung (0).
     *
     * @param Collection<int, OvertimeRequest> $approved pengajuan disetujui karyawan itu pada tanggal baris tersebut
     */
    public function recognizedMinutes(Attendance $attendance, Collection $approved): int
    {
        if (! $attendance->clock_in_at || ! $attendance->clock_out_at) {
            return 0;
        }

        return (int) $approved->sum(fn (OvertimeRequest $r) => self::overlapMinutes($attendance->clock_in_at, $attendance->clock_out_at, $r->starts_at, $r->ends_at));
    }

    /**
     * Menit kerja di luar jam resmi yang TIDAK punya persetujuan: setelah jam tutup toko, atau sepanjang hari bila toko libur, dikurangi jam yang
     * disetujui. Kelebihan di bawah UNAPPROVED_GRACE_MINUTES diabaikan. Dipakai rekap absensi untuk menandai lembur tanpa izin.
     *
     * @param Collection<int, OvertimeRequest> $approved
     * @param string|null $closingTime jam tutup toko hari itu ("H:i"), null bila tidak diketahui atau toko libur
     */
    public function unapprovedExtraMinutes(Attendance $attendance, Collection $approved, bool $storeClosed, ?string $closingTime): int
    {
        if (! in_array($attendance->entry_type, ['clock', 'manual'], true) || ! $attendance->clock_in_at || ! $attendance->clock_out_at) {
            return 0;
        }

        $in = $attendance->clock_in_at;
        $out = $attendance->clock_out_at;

        if ($storeClosed) {
            $worked = (int) $in->diffInMinutes($out);

            return max(0, $worked - $this->recognizedMinutes($attendance, $approved));
        }

        if (! $closingTime) {
            return 0;
        }

        $closing = $attendance->date->copy()->setTimeFromTimeString($closingTime);

        if ($out->lessThanOrEqualTo($closing)) {
            return 0;
        }

        $from = $in->greaterThan($closing) ? $in : $closing;
        $extra = (int) $from->diffInMinutes($out);
        $approvedAfter = (int) $approved->sum(fn (OvertimeRequest $r) => self::overlapMinutes($from, $out, $r->starts_at, $r->ends_at));
        $unapproved = max(0, $extra - $approvedAfter);

        return $unapproved < self::UNAPPROVED_GRACE_MINUTES ? 0 : $unapproved;
    }

    // ------------------------------------------------------------------ pemberitahuan

    private function hours(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 1, ',', ''), '0'), ',');
    }

    private function notifyApprovers(OvertimeRequest $request): void
    {
        $request->loadMissing('user:id,name', 'store:id,name', 'booking:id,booking_number');

        $recipients = User::where('is_active', true)
            ->get()
            ->filter(fn (User $u) => $u->id !== $request->user_id
                && ($u->isFullAccess() || ($u->isStoreManager() && (int) $u->store_id === (int) $request->store_id)))
            ->pluck('id');

        app(\App\Services\StaffNotifier::class)->toUsers(
            $recipients,
            'Pengajuan Lembur Baru',
            "{$request->user?->name} mengajukan lembur {$request->starts_at->format('d M Y')} pukul {$request->starts_at->format('H:i')}–{$request->ends_at->format('H:i')}. Putuskan sebelum jam mulai.",
            color: 'warning',
        );

        app(ApprovalMailer::class)->requested(
            'Lembur',
            $request->user?->name ?? '—',
            "{$request->starts_at->translatedFormat('d M Y')} pukul {$request->starts_at->format('H:i')}–{$request->ends_at->format('H:i')} · {$request->workLabel()}",
            $request->store?->name,
            $recipients,
        );
    }

    private function notifyRequester(OvertimeRequest $request, bool $approved): void
    {
        $when = "{$request->starts_at->format('d M Y')} pukul {$request->starts_at->format('H:i')}–{$request->ends_at->format('H:i')}";

        app(\App\Services\StaffNotifier::class)->toUsers(
            [$request->user_id],
            $approved ? 'Pengajuan Lembur Disetujui' : 'Pengajuan Lembur Ditolak',
            $approved
                ? "Lembur {$when} disetujui. Hanya jam itu yang diakui sebagai lembur."
                : "Lembur {$when} ditolak." . ($request->review_notes ? " Alasan: {$request->review_notes}" : ''),
            color: $approved ? 'success' : 'danger',
        );
    }
}
