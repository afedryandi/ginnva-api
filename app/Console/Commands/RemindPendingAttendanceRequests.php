<?php

namespace App\Console\Commands;

use App\Models\AttendanceCorrectionRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\StaffNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pengingat pengajuan Izin & Cuti dan Koreksi Absensi yang belum diputuskan (2026-11-11). Pemberitahuan pengajuan baru hanya sekali, jadi
 * pengajuan yang terlewat bisa menggantung tanpa kabar bagi karyawan yang mengajukannya. Pengingat pertama saat umur pengajuan
 * {\see self::AFTER_DAYS} hari, lalu berulang tiap {\see self::REPEAT_EVERY} hari selama masih menunggu. Dedup tanpa kolom baru: command jalan
 * sekali sehari dan hanya mengangkat pengajuan yang umurnya tepat jatuh pada hari pengingat.
 *
 * Penerima sama dengan pemberitahuan pengajuan baru: akses penuh (semua toko) dan store manager toko pengajuan itu.
 */
class RemindPendingAttendanceRequests extends Command
{
    protected $signature = 'attendance:remind-pending-requests';

    protected $description = 'Ingatkan pemutus tentang pengajuan izin/cuti dan koreksi absensi yang menunggu terlalu lama';

    public const AFTER_DAYS = 2;

    public const REPEAT_EVERY = 3;

    public function handle(StaffNotifier $notifier): int
    {
        $today = Carbon::today();

        $due = fn ($request) => $this->isDue($request->created_at, $today);

        $leaves = LeaveRequest::where('status', 'pending')->get()->filter($due);
        $corrections = AttendanceCorrectionRequest::where('status', 'pending')->get()->filter($due);

        if ($leaves->isEmpty() && $corrections->isEmpty()) {
            $this->info('Tidak ada pengajuan yang perlu diingatkan.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach (User::where('is_active', true)->get() as $user) {
            $mine = fn ($requests) => $user->isFullAccess()
                ? $requests
                : ($user->isStoreManager() ? $requests->where('store_id', $user->store_id) : $requests->take(0));

            $leaveCount = $mine($leaves)->count();
            $correctionCount = $mine($corrections)->count();

            if ($leaveCount + $correctionCount === 0) {
                continue;
            }

            $parts = array_filter([
                $leaveCount > 0 ? "{$leaveCount} pengajuan Izin & Cuti" : null,
                $correctionCount > 0 ? "{$correctionCount} Koreksi Absensi" : null,
            ]);

            $notifier->toUsers(
                [$user->id],
                'Pengajuan Menunggu Keputusan',
                implode(' dan ', $parts) . ' belum diputuskan lebih dari ' . self::AFTER_DAYS . ' hari. Karyawan menunggu kabar; buka menu terkait untuk menyetujui atau menolak.',
                coalesceKey: 'attendance-pending-requests',
                color: 'warning',
            );
            $sent++;
        }

        $this->info("Selesai: {$sent} pemutus diingatkan.");

        return self::SUCCESS;
    }

    /** Umur pengajuan (hari kalender) tepat pada hari pengingat: AFTER_DAYS, lalu tiap REPEAT_EVERY hari sesudahnya. */
    private function isDue(?Carbon $createdAt, Carbon $today): bool
    {
        if (! $createdAt) {
            return false;
        }

        $age = (int) $createdAt->copy()->startOfDay()->diffInDays($today, false);

        return $age >= self::AFTER_DAYS && ($age - self::AFTER_DAYS) % self::REPEAT_EVERY === 0;
    }
}
