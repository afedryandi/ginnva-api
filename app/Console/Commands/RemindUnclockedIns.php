<?php

namespace App\Console\Commands;

use App\Services\AttendanceAudience;
use App\Services\StaffNotifier;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Pengingat absen masuk ke KARYAWAN-nya sendiri, tepat pada jam buka toko (2026-11-11, permintaan pemilik; semula 5 menit sesudahnya).
 * NotifyMissingClockins baru memberi tahu staf toko sekitar 45 menit sesudah jam buka (toleransi telat + tenggang); pengingat ini lebih awal
 * dan langsung ke yang bersangkutan, supaya yang hanya lupa menekan tombol sempat absen sebelum tercatat telat jauh.
 *
 * Jalan tiap 5 menit, jadi pengingat tiba paling lambat 5 menit dari jam buka. Dedup dengan kunci cache per karyawan per hari (Cache::add atomik), bukan jendela waktu yang rapuh terhadap jadwal
 * cron yang bergeser: satu karyawan dapat satu pengingat per hari. Jendela atas {@see self::CATCH_UP_MINUTES} menutup putaran yang terlewat.
 */
class RemindUnclockedIns extends Command
{
    protected $signature = 'attendance:remind-clockins';

    protected $description = 'Ingatkan karyawan yang belum absen masuk tepat pada jam buka toko';

    /** Menit setelah jam buka (0 = tepat jam buka, keputusan pemilik 2026-11-11). */
    public const AFTER_MINUTES = 0;

    private const CATCH_UP_MINUTES = 30;

    public function handle(AttendanceAudience $audience, StaffNotifier $notifier): int
    {
        $today = Carbon::today();
        $now = Carbon::now();
        $reminded = 0;

        foreach (Store::where('is_active', true)->get() as $store) {
            if ($store->isClosedOn($today)) {
                continue;
            }

            $clockedIn = $audience->clockedInIds($store, $today);
            $onLeave = $audience->onLeaveIds($store, $today);
            $userIds = [];

            foreach ($audience->expected($store, $today) as $user) {
                if ($clockedIn->contains($user->id) || $onLeave->contains($user->id)) {
                    continue;
                }

                $start = $audience->startTime($user->id, $store, $today);
                if (! $start) {
                    continue;
                }

                $threshold = $today->copy()->setTimeFromTimeString($start)->addMinutes(self::AFTER_MINUTES);

                if ($now->lt($threshold) || $now->gte($threshold->copy()->addMinutes(self::CATCH_UP_MINUTES))) {
                    continue;
                }

                // Cache::add hanya berhasil sekali per kunci: satu pengingat per karyawan per hari.
                if (Cache::add("attendance-reminder:in:{$user->id}:{$today->toDateString()}", 1, $today->copy()->endOfDay())) {
                    $userIds[] = $user->id;
                }
            }

            if ($userIds !== []) {
                $notifier->toUsers(
                    $userIds,
                    'Jangan Lupa Absen Masuk',
                    'Toko sudah buka. Jangan lupa absen masuk: buka menu Absensi lalu tekan Absen Masuk dari area toko.',
                    color: 'warning',
                );
                $reminded += count($userIds);
            }
        }

        $this->info("Selesai: {$reminded} karyawan diingatkan absen masuk.");

        return self::SUCCESS;
    }
}
