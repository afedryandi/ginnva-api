<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\FieldDutyRequest;
use App\Models\Store;
use App\Services\StaffNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Pengingat foto akhir dinas luar ke KARYAWAN-nya, 15 menit sebelum jam selesai dinas (keputusan pemilik 2026-12-21). Dinas sebagian hari memakai jam
 * selesai yang diajukan; dinas sehari penuh tidak punya jam selesai, jadi memakai jam selesai shift atau jam tutup toko hari itu (Attendance::expectedEndOn).
 * Yang diingatkan: dinas disetujui hari ini yang foto akhirnya belum dikirim. Bila foto awal juga belum ada, pesannya menyebut keduanya.
 *
 * Jalan tiap 5 menit. Dedup dengan kunci cache per pengajuan (Cache::add atomik): satu pengingat per pengajuan. Jendela atas
 * {@see self::CATCH_UP_MINUTES} menutup putaran yang terlewat.
 */
class RemindFieldDutyEndPhoto extends Command
{
    protected $signature = 'field-duty:remind-end-photo';

    protected $description = 'Ingatkan karyawan yang belum mengirim foto akhir dinas luar, 15 menit sebelum jam selesai dinas';

    public const BEFORE_MINUTES = 15;

    private const CATCH_UP_MINUTES = 60;

    public function handle(): int
    {
        $today = Carbon::today();
        $now = Carbon::now();
        $reminded = 0;

        $requests = FieldDutyRequest::withoutGlobalScopes()
            ->with(['user', 'store'])
            ->where('status', FieldDutyRequest::STATUS_APPROVED)
            ->whereDate('date', $today->toDateString())
            ->whereNull('end_photo')
            ->get();

        foreach ($requests as $request) {
            if (! $request->user?->is_active) {
                continue;
            }

            $end = $request->isPartialDay()
                ? $request->endsAt()
                : ($request->store ? Attendance::expectedEndOn($request->user, $request->store, $today->copy()) : null);

            if (! $end) {
                continue;
            }

            $threshold = $end->copy()->subMinutes(self::BEFORE_MINUTES);

            if ($now->lt($threshold) || $now->gte($threshold->copy()->addMinutes(self::CATCH_UP_MINUTES))) {
                continue;
            }

            if (! Cache::add("field-duty-reminder:end-photo:{$request->id}", 1, $today->copy()->endOfDay())) {
                continue;
            }

            $both = blank($request->location_photo);

            app(StaffNotifier::class)->toUsers(
                [$request->user_id],
                $both ? 'Jangan Lupa Foto Dinas Luar' : 'Jangan Lupa Foto Akhir Dinas',
                $both
                    ? 'Foto awal dan foto akhir dinas luar belum dikirim. Kirim dari layar Dinas Luar sebelum dinas selesai; tanpa foto, dinas sehari penuh tidak dihitung hadir.'
                    : 'Dinas luar Anda hampir selesai. Kirim foto akhir dari layar Dinas Luar, pastikan GPS nyala; tanpa foto, dinas sehari penuh tidak dihitung hadir.',
                color: 'warning',
            );
            $reminded++;
        }

        $this->info("Selesai: {$reminded} karyawan diingatkan foto akhir dinas luar.");

        return self::SUCCESS;
    }
}
