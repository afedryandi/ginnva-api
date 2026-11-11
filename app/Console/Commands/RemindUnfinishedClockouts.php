<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Store;
use App\Services\AttendanceAudience;
use App\Services\StaffNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Pengingat absen pulang ke KARYAWAN-nya sendiri, 5 menit setelah jam tutup toko (2026-11-11, permintaan pemilik). NotifyForgottenClockouts baru
 * memberi tahu staf toko keesokan paginya, saat karyawan sudah tidak bisa absen pulang lagi dan jamnya harus dikoreksi lewat pengajuan.
 * Absen pulang HARUS dilakukan di dalam radius toko (ditolak server bila di luar), jadi pengingat dikirim cepat, selagi karyawan kemungkinan
 * masih di toko, dan pesannya menyebut radiusnya. Hari yang lupa absen pulang tidak ikut Total Jam Kerja di rekap absensi.
 *
 * Jalan tiap 5 menit. Dedup dengan kunci cache per karyawan per hari (Cache::add atomik): satu pengingat per karyawan per hari. Jendela atas
 * {@see self::CATCH_UP_MINUTES} menutup putaran yang terlewat.
 */
class RemindUnfinishedClockouts extends Command
{
    protected $signature = 'attendance:remind-clockouts';

    protected $description = 'Ingatkan karyawan yang sudah absen masuk tapi belum absen pulang 5 menit setelah jam tutup toko';

    public const AFTER_MINUTES = 5;

    private const CATCH_UP_MINUTES = 60;

    public function handle(AttendanceAudience $audience, StaffNotifier $notifier): int
    {
        $today = Carbon::today();
        $now = Carbon::now();
        $reminded = 0;

        foreach (Store::where('is_active', true)->get() as $store) {
            if ($store->isClosedOn($today)) {
                continue;
            }

            $open = Attendance::where('store_id', $store->id)
                ->where('date', $today->toDateString())
                ->where('entry_type', 'clock')
                ->whereNotNull('clock_in_at')
                ->whereNull('clock_out_at')
                ->with('user:id,name,is_active')
                ->get();

            $userIds = [];

            foreach ($open as $row) {
                if (! $row->user?->is_active) {
                    continue;
                }

                $end = $audience->endTime($row->user_id, $store, $today);
                if (! $end) {
                    continue;
                }

                $threshold = $today->copy()->setTimeFromTimeString($end)->addMinutes(self::AFTER_MINUTES);

                if ($now->lt($threshold) || $now->gte($threshold->copy()->addMinutes(self::CATCH_UP_MINUTES))) {
                    continue;
                }

                if (Cache::add("attendance-reminder:out:{$row->user_id}:{$today->toDateString()}", 1, $today->copy()->endOfDay())) {
                    $userIds[] = $row->user_id;
                }
            }

            if ($userIds !== []) {
                $radius = (int) ($store->attendance_radius_meters ?? Attendance::DEFAULT_RADIUS_METERS);

                $notifier->toUsers(
                    $userIds,
                    'Jangan Lupa Absen Pulang',
                    "Toko sudah tutup tapi Anda belum absen pulang. Absen keluar hanya berhasil di dalam radius {$radius} meter dari toko, jadi lakukan sebelum meninggalkan toko. Bila sudah di luar radius, ajukan Koreksi Absensi.",
                    color: 'warning',
                );
                $reminded += count($userIds);
            }
        }

        $this->info("Selesai: {$reminded} karyawan diingatkan absen pulang.");

        return self::SUCCESS;
    }
}
