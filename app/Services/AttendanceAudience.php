<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Siapa yang diharapkan absen di sebuah toko pada satu hari, dan jam acuannya (2026-11-11). Dipakai bersama oleh peringatan belum absen
 * masuk ke staf toko (NotifyMissingClockins), pengingat absen masuk ke karyawan (RemindUnclockedIns), dan pengingat absen pulang
 * (RemindUnfinishedClockouts), supaya ketiganya memakai aturan yang sama.
 *
 * Jam acuan: shift pribadi bila ada (modul Jadwal Kerja, mati bawaan), selain itu jam buka dan jam tutup toko pada hari itu.
 */
class AttendanceAudience
{
    /** Karyawan aktif toko yang wajib absen pada $day (bukan mitra, bukan Direksi, sudah lewat Mulai Wajib Absen, belum lewat hari kerja terakhir). */
    public function expected(Store $store, Carbon $day): Collection
    {
        return User::where('store_id', $store->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('last_work_date')->orWhereDate('last_work_date', '>=', $day))
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
            ->requiresAttendance()
            ->attendanceStarted($day)
            ->get();
    }

    /** Id karyawan yang sudah absen masuk hari itu. */
    public function clockedInIds(Store $store, Carbon $day): Collection
    {
        return Attendance::where('store_id', $store->id)
            ->where('date', $day->toDateString())
            ->whereNotNull('clock_in_at')
            ->pluck('user_id');
    }

    /**
     * Id karyawan yang tidak diharapkan absen di toko pada hari itu: izin/cuti disetujui (baris Izin/Cuti di Absensi baru dibuat dini hari
     * berikutnya) dan dinas luar sehari penuh (disetujui, atau sudah tercatat sebagai Dinas Luar). Dinas sebagian hari tidak termasuk: karyawan tetap wajib absen.
     */
    public function onLeaveIds(Store $store, Carbon $day): Collection
    {
        return LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->pluck('user_id')
            ->merge(Attendance::where('store_id', $store->id)->where('date', $day->toDateString())->whereIn('entry_type', ['leave', 'field_duty'])->pluck('user_id'))
            ->merge(\App\Models\FieldDutyRequest::withoutGlobalScopes()->where('store_id', $store->id)->where('status', 'approved')->whereNull('start_time')->whereDate('date', $day)->pluck('user_id'));
    }

    /** Jam mulai kerja karyawan hari itu ("H:i" atau "H:i:s"): shift pribadi, selain itu jam buka toko; null bila tidak diketahui. */
    public function startTime(int $userId, Store $store, Carbon $day): ?string
    {
        return Attendance::resolveShiftFor($userId, $day)?->start_time ?? $store->openingTimeOn($day);
    }

    /**
     * Jam selesai kerja karyawan hari itu: akhir shift pribadi, selain itu jam tutup toko; bila ada lembur yang disetujui, jam selesai lembur
     * (yang lebih akhir). Null bila tidak diketahui (mis. toko libur dan tanpa lembur).
     */
    public function endTime(int $userId, Store $store, Carbon $day): ?string
    {
        $normal = Attendance::resolveShiftFor($userId, $day)?->end_time ?? $store->closingTimeOn($day);

        $overtimeEnd = OvertimeRequest::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('status', OvertimeRequest::STATUS_APPROVED)
            ->whereDate('date', $day->toDateString())
            ->max('ends_at');

        if (! $overtimeEnd) {
            return $normal;
        }

        $overtime = Carbon::parse($overtimeEnd)->format('H:i:s');

        return $normal === null || substr($overtime, 0, 5) > substr($normal, 0, 5) ? $overtime : $normal;
    }
}
