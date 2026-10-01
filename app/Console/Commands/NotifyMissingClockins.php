<?php

namespace App\Console\Commands;

use App\Filament\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\Store;
use App\Models\User;
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

    public function handle(PushNotificationService $push): int
    {
        $today = Carbon::today();
        $now = Carbon::now();
        $windowStart = $now->copy()->subMinutes(self::DETECTION_WINDOW_MINUTES);

        $notified = 0;

        foreach (Store::where('is_active', true)->get() as $store) {
            if ($store->isClosedOn($today)) {
                continue;
            }

            $employees = User::where('store_id', $store->id)
                ->where('is_active', true)
                ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
                ->get();

            $alreadyClockedIn = Attendance::where('store_id', $store->id)
                ->where('date', $today->toDateString())
                ->whereNotNull('clock_in_at')
                ->pluck('user_id');

            $toleranceMinutes = $store->late_tolerance_minutes ?? Attendance::DEFAULT_LATE_TOLERANCE_MINUTES;
            $lateNames = [];

            foreach ($employees as $user) {
                if ($alreadyClockedIn->contains($user->id)) {
                    continue;
                }

                $shift = Attendance::resolveShiftFor($user->id, $today);
                if (! $shift) {
                    continue;
                }

                $threshold = $today->copy()->setTimeFromTimeString($shift->start_time)
                    ->addMinutes($toleranceMinutes + self::GRACE_MINUTES);

                if ($threshold->between($windowStart, $now)) {
                    $lateNames[] = $user->name;
                }
            }

            if (! empty($lateNames)) {
                $push->sendToStoreStaff(
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
