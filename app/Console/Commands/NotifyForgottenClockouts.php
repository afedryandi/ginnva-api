<?php

namespace App\Console\Commands;

use App\Filament\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Services\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Gap DIPERBAIKI 2026-09-26 (audit Absensi Karyawan, "tidak ada
 * notifikasi proaktif untuk staff yang lupa clock-out") -- SEBELUMNYA
 * baris absensi yang clock_in_at terisi tapi clock_out_at kosong (staff
 * lupa/HP mati sebelum sempat absen pulang) dibiarkan begitu saja
 * selamanya, admin harus buka filter "Perlu Ditinjau" di AttendanceResource
 * secara manual untuk sadar ada yang perlu dikoreksi. Jalan tiap pagi
 * (SETELAH attendance:mark-absences jam 01:00, supaya tidak tumpang
 * tindih) -- cek baris entry_type='clock' dari HARI-HARI SEBELUMNYA
 * (bukan hari ini, staff masih bisa clock-out normal sampai tengah
 * malam) yang masih terbuka & belum pernah dinotifikasi.
 */
class NotifyForgottenClockouts extends Command
{
    protected $signature = 'attendance:notify-forgotten-clockouts';

    protected $description = 'Kirim notifikasi ke staff toko untuk baris absensi hari sebelumnya yang lupa clock-out';

    public function handle(PushNotificationService $push): int
    {
        $rows = Attendance::where('entry_type', 'clock')
            ->whereNotNull('clock_in_at')
            ->whereNull('clock_out_at')
            ->whereNull('forgotten_clockout_notified_at')
            ->whereDate('date', '<', Carbon::today())
            ->with('user:id,name', 'store:id,name')
            ->get();

        $notified = 0;

        foreach ($rows->groupBy('store_id') as $storeId => $storeRows) {
            if (! $storeId) {
                continue;
            }

            $names = $storeRows->map(fn (Attendance $a) => "{$a->user?->name} ({$a->date->format('d M')})")->implode(', ');

            $push->sendToStoreStaff(
                (int) $storeId,
                'Ada Absensi Belum Lengkap',
                "Lupa absen pulang: {$names}. Koreksi lewat menu Absensi kalau perlu.",
                resourceClass: AttendanceResource::class,
            );

            foreach ($storeRows as $row) {
                $row->update(['forgotten_clockout_notified_at' => now()]);
                $notified++;
            }
        }

        $this->info("Selesai: {$notified} baris absensi terbuka dinotifikasi.");

        return self::SUCCESS;
    }
}
