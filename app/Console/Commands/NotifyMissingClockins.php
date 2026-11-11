<?php

namespace App\Console\Commands;

use App\Filament\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\Store;
use App\Services\AttendanceAudience;
use App\Services\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Gap DIPERBAIKI 2026-09-26 (audit Absensi Karyawan, "tidak ada
 * deteksi staff yang belum absen padahal shift sudah mulai") --
 * SEBELUMNYA staff bisa absen kapan saja (atau tidak sama sekali)
 * tanpa terdeteksi sebagai penyimpangan dari jadwalnya SAMPAI
 * attendance:mark-absences jalan dini hari BESOKNYA (retroaktif,
 * ditandai Alpha) -- tidak ada peringatan SAAT HARI ITU JUGA supaya
 * admin toko bisa segera tindak lanjut (telepon staff, dst).
 *
 * 2026-11-11: karyawan tanpa shift kini dibandingkan terhadap jam buka toko (sebelumnya dilewati, jadi peringatan tidak pernah terkirim
 * di Ginnva yang tidak memakai shift), dan yang sudah punya izin/cuti disetujui hari ini dikecualikan.
 *
 * Dijadwalkan JALAN TIAP JAM (lihat routes/console.php) antara jam
 * operasional -- dedup TANPA kolom baru: window deteksi cuma
 * [sekarang-65 menit, sekarang], jadi 1 staff yang telat cuma kena
 * notifikasi SEKALI (saat ambang batasnya baru saja terlewati),
 * bukan berulang tiap command jalan.
 */
class NotifyMissingClockins extends Command
{
    protected $signature = 'attendance:notify-missing-clockins';

    protected $description = 'Kirim notifikasi ke staff toko untuk karyawan yang belum absen masuk padahal shift-nya sudah mulai';

    private const GRACE_MINUTES = 30;

    private const DETECTION_WINDOW_MINUTES = 65;

    public function handle(PushNotificationService $push, AttendanceAudience $audience): int
    {
        $today = Carbon::today();
        $now = Carbon::now();
        $windowStart = $now->copy()->subMinutes(self::DETECTION_WINDOW_MINUTES);

        $notified = 0;

        foreach (Store::where('is_active', true)->get() as $store) {
            if ($store->isClosedOn($today)) {
                continue;
            }

            $alreadyClockedIn = $audience->clockedInIds($store, $today);
            $onLeave = $audience->onLeaveIds($store, $today);
            $toleranceMinutes = $store->late_tolerance_minutes ?? Attendance::DEFAULT_LATE_TOLERANCE_MINUTES;
            $lateNames = [];

            foreach ($audience->expected($store, $today) as $user) {
                if ($alreadyClockedIn->contains($user->id) || $onLeave->contains($user->id)) {
                    continue;
                }

                // Ginnva tidak memakai shift (keputusan 2026-10-17): tanpa shift, acuannya jam buka toko hari ini. Sebelumnya karyawan tanpa
                // shift dilewati begitu saja sehingga peringatan ini tidak pernah terkirim.
                $startTime = $audience->startTime($user->id, $store, $today);
                if (! $startTime) {
                    continue;
                }

                $threshold = $today->copy()->setTimeFromTimeString($startTime)
                    ->addMinutes($toleranceMinutes + self::GRACE_MINUTES);

                if ($threshold->between($windowStart, $now)) {
                    $lateNames[] = $user->name;
                }
            }

            if (! empty($lateNames)) {
                app(\App\Services\StaffNotifier::class)->toStoreStaff(
                    $store->id,
                    'Karyawan Belum Absen Masuk',
                    'Sudah lewat jadwal shift tapi belum absen: ' . implode(', ', $lateNames) . '.',
                    resourceClass: AttendanceResource::class,
                );
                $notified += count($lateNames);
            }
        }

        $this->info("Selesai: {$notified} karyawan terdeteksi belum absen padahal shift sudah mulai.");

        return self::SUCCESS;
    }
}
