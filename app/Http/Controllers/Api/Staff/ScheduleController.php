<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\EmployeeScheduleAssignment;
use App\Models\LeaveRequest;
use App\Models\ScheduleDayOverride;
use App\Models\Shift;
use App\Models\WorkSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Jadwal kerja mingguan MILIK SENDIRI dari mobile app -- gap ditutup
 * 2026-09-30 (audit fitur relevan Majoo Teams): WorkSchedule/
 * EmployeeScheduleAssignment/ScheduleDayOverride sudah dibangun sejak
 * 2026-09-22 tapi sebelumnya cuma bisa dilihat lewat kalender Filament
 * (WorkScheduleCalendarReport, khusus admin/store manager) -- staff
 * sendiri tidak pernah punya cara melihat jadwal shift mingguannya
 * sendiri kecuali ditanya/diberi tahu manual. SENGAJA tidak dibatasi
 * hasMenuAccess() (sama pola dengan AttendanceController/PayrollController)
 * -- melihat jadwal sendiri adalah hak dasar semua staff, bukan izin
 * modul opsional.
 *
 * Resolusi per hari MENIRU PERSIS urutan prioritas
 * WorkScheduleCalendarReport::getCalendar() supaya kedua sisi (admin &
 * staff) selalu menunjukkan jadwal yang sama: override harian dulu
 * (kalau ada), baru fallback ke template WorkSchedule dari penugasan
 * yang aktif pada tanggal itu.
 */
class ScheduleController extends Controller
{
    /**
     * GET /api/staff/schedule?minggu=2026-09-28
     * `minggu` boleh tanggal apa saja dalam minggu yang mau dilihat --
     * di-snap ke Senin minggu itu. Default: minggu berjalan.
     */
    public function index(Request $request)
    {
        $request->validate(['minggu' => 'nullable|date']);

        $user = $request->user('api');

        if (! $user->store_id) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini belum terhubung ke toko mana pun, belum punya jadwal kerja.',
            ], 422);
        }

        $anchor = $request->filled('minggu') ? Carbon::parse($request->minggu) : Carbon::now();
        $weekStart = $anchor->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $days = collect(range(0, 6))->map(fn (int $i) => $weekStart->copy()->addDays($i));
        $dayCodes = WorkSchedule::DAYS; // ['mon', ..., 'sun'] -- index selaras dgn $days (Senin index 0)

        $assignments = EmployeeScheduleAssignment::where('user_id', $user->id)
            ->whereDate('effective_from', '<=', $weekEnd)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $weekStart))
            ->with('workSchedule')
            ->get();

        $overrides = ScheduleDayOverride::where('user_id', $user->id)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get()
            ->keyBy(fn (ScheduleDayOverride $o) => $o->date->toDateString());

        $shiftIdsFromSchedules = $assignments->pluck('workSchedule')->filter()
            ->flatMap(fn (WorkSchedule $ws) => collect($ws->days)->pluck('shift_id'))
            ->filter();

        $shiftsById = Shift::withoutGlobalScopes()
            ->whereIn('id', $shiftIdsFromSchedules->merge($overrides->pluck('shift_id')->filter())->unique())
            ->get()
            ->keyBy('id');

        $leaves = LeaveRequest::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $weekEnd)
            ->whereDate('end_date', '>=', $weekStart)
            ->get(['id', 'type', 'start_date', 'end_date']);

        $attendances = Attendance::where('user_id', $user->id)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->get()
            ->keyBy(fn (Attendance $a) => $a->date->toDateString());

        $today = Carbon::today();

        $rows = $days->map(function (Carbon $date, int $i) use ($overrides, $assignments, $dayCodes, $shiftsById, $leaves, $attendances, $today) {
            $dateKey = $date->toDateString();
            $override = $overrides->get($dateKey);

            if ($override) {
                $shift = $override->shift_id ? $shiftsById->get($override->shift_id) : null;
                $cell = $shift
                    ? ['label' => $shift->name, 'time' => $this->formatShiftTime($shift), 'is_off' => false]
                    : ['label' => 'Libur', 'time' => null, 'is_off' => true];
                $cell['is_override'] = true;
                $cell['reason'] = $override->reason;
            } else {
                // sortByDesc('effective_from') supaya konsisten dengan
                // WorkScheduleCalendarReport/Attendance::resolveShiftFor()
                // kalau ada penugasan tumpang tindih.
                $assignment = $assignments->sortByDesc('effective_from')->first(fn (EmployeeScheduleAssignment $a) => $a->effective_from->lte($date)
                    && ($a->effective_to === null || $a->effective_to->gte($date)));

                if (! $assignment || ! $assignment->workSchedule) {
                    $cell = ['label' => '—', 'time' => null, 'is_off' => false, 'is_override' => false, 'reason' => null];
                } else {
                    $shiftId = $assignment->workSchedule->shiftIdFor($dayCodes[$i]);
                    $shift = $shiftId ? $shiftsById->get($shiftId) : null;
                    $cell = $shift
                        ? ['label' => $shift->name, 'time' => $this->formatShiftTime($shift), 'is_off' => false]
                        : ['label' => 'Libur', 'time' => null, 'is_off' => true];
                    $cell['is_override'] = false;
                    $cell['reason'] = null;
                }
            }

            $leave = $leaves->first(fn (LeaveRequest $l) => $l->start_date->lte($date) && $l->end_date->gte($date));
            $attendance = $attendances->get($dateKey);

            return [
                'date' => $dateKey,
                'day_name' => WorkSchedule::DAY_LABELS[$dayCodes[$i]],
                'is_today' => $date->isSameDay($today),
                'schedule' => $cell,
                'leave_type' => $leave?->type,
                'clock_in_at' => $attendance?->clock_in_at?->toIso8601String(),
                'clock_out_at' => $attendance?->clock_out_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'days' => $rows,
        ]);
    }

    private function formatShiftTime(Shift $shift): string
    {
        return Carbon::parse($shift->start_time)->format('H:i') . '–' . Carbon::parse($shift->end_time)->format('H:i');
    }
}
