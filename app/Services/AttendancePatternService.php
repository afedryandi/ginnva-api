<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\EmployeeScheduleAssignment;
use App\Models\ScheduleDayOverride;
use App\Models\Shift;
use App\Models\WorkSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Kategorisasi detail keterlambatan/kecepatan" (audit Majoo, f23),
 * dibangun 2026-09-22. Attendance SEBELUMNYA cuma menyimpan sisi
 * negatif (late_minutes/early_leave_minutes) — tidak ada cara tahu
 * "datang lebih awal dari jadwal" atau "pulang lebih lambat/lembur"
 * karena sistem tidak menyimpan jadwal target per baris (lihat catatan
 * lama di AttendanceReport.php). Modul Jadwal Kerja (audit Majoo
 * lain, dibangun hari yang sama) SEKARANG menyediakan data itu --
 * service ini membandingkan clock_in_at/clock_out_at terhadap Shift
 * yang berlaku (via EmployeeScheduleAssignment + ScheduleDayOverride,
 * SAMA prioritas resolusi dengan WorkScheduleCalendarReport) untuk
 * melengkapi 4 kategori lama jadi 6:
 *
 * 1. Tepat Waktu       — tidak telat, tidak pulang cepat, tidak masuk
 *                        lebih awal, tidak pulang lebih lambat.
 * 2. Terlambat Masuk    — late_minutes > 0 (data lama, tidak berubah).
 * 3. Pulang Lebih Cepat — early_leave_minutes > 0 (data lama).
 * 4. Masuk Lebih Awal   — clock_in_at SEBELUM jam mulai shift.
 * 5. Lembur/Pulang Lambat — clock_out_at SETELAH jam selesai shift.
 * 6. Tidak Ada Jadwal   — tidak ada Shift yang bisa dibandingkan
 *                         (karyawan belum di-assign Jadwal Kerja hari
 *                         itu) -- BUKAN error, cuma tidak bisa
 *                         diklasifikasi kategori 4/5 di atas.
 *
 * Kategori TIDAK saling eksklusif (1 baris bisa "Terlambat Masuk" DAN
 * "Lembur" sekaligus, mis. datang telat tapi pulang jauh lebih malam)
 * -- sama filosofi counting non-eksklusif yang sudah dipakai
 * AttendanceReport (onTimeCount/lateCount/earlyLeaveCount).
 */
class AttendancePatternService
{
    /**
     * @param  Collection<int, Attendance>  $attendances
     * @return Collection<int, array{attendance: Attendance, isLate: bool, isEarlyLeave: bool, isEarlyArrival: bool, isOvertime: bool, hasSchedule: bool}>
     */
    public function classify(Collection $attendances): Collection
    {
        $clockRows = $attendances->filter(fn (Attendance $a) => in_array($a->entry_type, ['clock', 'manual', 'field_duty'], true) && $a->user_id !== null);

        if ($clockRows->isEmpty()) {
            return $attendances->map(fn (Attendance $a) => $this->buildRow($a, null));
        }

        $userIds = $clockRows->pluck('user_id')->unique()->values();
        $minDate = $clockRows->min('date');
        $maxDate = $clockRows->max('date');

        $assignments = EmployeeScheduleAssignment::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('effective_from', '<=', $maxDate)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $minDate))
            ->with('workSchedule')
            ->get()
            ->groupBy('user_id');

        $overrides = ScheduleDayOverride::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('date', [$minDate, $maxDate])
            ->get()
            ->groupBy('user_id');

        $shiftIds = $assignments->flatten()->pluck('workSchedule')->filter()
            ->flatMap(fn (WorkSchedule $ws) => collect($ws->days)->pluck('shift_id'))
            ->merge($overrides->flatten()->pluck('shift_id'))
            ->filter()
            ->unique();

        $shiftsById = Shift::whereIn('id', $shiftIds)->get()->keyBy('id');
        $dayCodes = WorkSchedule::DAYS;

        return $attendances->map(function (Attendance $a) use ($assignments, $overrides, $shiftsById, $dayCodes) {
            if (! in_array($a->entry_type, ['clock', 'manual', 'field_duty'], true) || $a->user_id === null) {
                return $this->buildRow($a, null);
            }

            $date = Carbon::parse($a->date);
            $override = $overrides->get($a->user_id, collect())
                ->first(fn (ScheduleDayOverride $o) => $o->date->isSameDay($date));

            $shift = null;
            if ($override) {
                $shift = $override->shift_id ? $shiftsById->get($override->shift_id) : null;
            } else {
                $assignment = $assignments->get($a->user_id, collect())
                    ->first(fn (EmployeeScheduleAssignment $asg) => $asg->effective_from->lte($date)
                        && ($asg->effective_to === null || $asg->effective_to->gte($date)));

                if ($assignment?->workSchedule) {
                    // dayOfWeekIso: 1=Senin..7=Minggu, selaras dgn indeks
                    // WorkSchedule::DAYS (['mon',...,'sun']).
                    $shiftId = $assignment->workSchedule->shiftIdFor($dayCodes[$date->dayOfWeekIso - 1]);
                    $shift = $shiftId ? $shiftsById->get($shiftId) : null;
                }
            }

            return $this->buildRow($a, $shift);
        });
    }

    private function buildRow(Attendance $a, ?Shift $shift): array
    {
        $isEarlyArrival = false;
        $isOvertime = false;

        if ($shift && $a->clock_in_at) {
            $shiftStart = Carbon::parse($a->date)->setTimeFromTimeString($shift->start_time);
            $isEarlyArrival = $a->clock_in_at->lt($shiftStart);
        }

        if ($shift && $a->clock_out_at) {
            $shiftEnd = Carbon::parse($a->date)->setTimeFromTimeString($shift->end_time);
            // Shift lintas tengah malam (mis. 22:00-06:00) -- jam selesai
            // secara kalender jatuh di HARI BERIKUTNYA.
            if ($shiftEnd->lt(Carbon::parse($a->date)->setTimeFromTimeString($shift->start_time))) {
                $shiftEnd->addDay();
            }
            $isOvertime = $a->clock_out_at->gt($shiftEnd);
        }

        return [
            'attendance' => $a,
            'isLate' => $a->late_minutes > 0,
            'isEarlyLeave' => $a->early_leave_minutes > 0,
            'isEarlyArrival' => $isEarlyArrival,
            'isOvertime' => $isOvertime,
            'hasSchedule' => $shift !== null,
        ];
    }
}
