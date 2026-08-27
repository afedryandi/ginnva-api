<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\Store;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Jalan tiap dini hari (lihat routes/console.php) untuk HARI KEMARIN (yang
 * sudah pasti selesai penuh) — supaya hari tanpa kehadiran SELALU punya 1
 * baris Attendance, tidak dibiarkan kosong tanpa keterangan:
 * - Ada LeaveRequest disetujui yang mencakup tanggal itu -> entry_type
 *   'leave'.
 * - Tidak ada -> entry_type 'alpha' (mangkir tanpa keterangan).
 * Karyawan yang SUDAH punya baris hari itu (clock/manual/field_duty)
 * dilewati — command ini cuma mengisi yang kosong, tidak pernah menimpa.
 */
class MarkAbsences extends Command
{
    protected $signature = 'attendance:mark-absences';

    protected $description = 'Buat baris Attendance otomatis (Alpha/Izin) untuk karyawan yang kemarin tidak punya catatan kehadiran sama sekali';

    public function handle(): int
    {
        $yesterday = Carbon::yesterday();
        $alphaCount = 0;
        $leaveCount = 0;

        foreach (Store::where('is_active', true)->get() as $store) {
            if ($store->isClosedOn($yesterday)) {
                continue;
            }

            $employees = User::where('store_id', $store->id)
                ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
                ->get();

            $alreadyRecorded = Attendance::where('store_id', $store->id)
                ->where('date', $yesterday->toDateString())
                ->pluck('user_id');

            foreach ($employees as $user) {
                if ($alreadyRecorded->contains($user->id)) {
                    continue;
                }

                $onApprovedLeave = LeaveRequest::where('user_id', $user->id)
                    ->where('status', 'approved')
                    ->whereDate('start_date', '<=', $yesterday)
                    ->whereDate('end_date', '>=', $yesterday)
                    ->exists();

                Attendance::create([
                    'user_id'    => $user->id,
                    'store_id'   => $store->id,
                    'date'       => $yesterday->toDateString(),
                    'entry_type' => $onApprovedLeave ? 'leave' : 'alpha',
                ]);

                $onApprovedLeave ? $leaveCount++ : $alphaCount++;
            }
        }

        $this->info("Selesai untuk {$yesterday->translatedFormat('d F Y')}: {$alphaCount} ditandai Alpha, {$leaveCount} ditandai Izin/Cuti.");

        return self::SUCCESS;
    }
}
