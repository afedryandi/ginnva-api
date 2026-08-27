<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Absen mandiri (self-service) dari mobile app — SENGAJA tidak dibatasi
 * hasMenuAccess() seperti resource inventaris/booking lain, karena absen
 * itu kewajiban dasar semua staff, bukan izin opsional yang bisa lupa
 * dicentang admin. Review/entri manual staff LAIN tetap lewat
 * AttendanceResource/LeaveRequestResource di Filament (dibatasi
 * hasMenuAccess seperti biasa) — controller ini murni "punya diri
 * sendiri".
 */
class AttendanceController extends Controller
{
    /**
     * GET /api/staff/attendance/today
     * Status absen hari ini + toko tempat user terdaftar (buat app tahu
     * radius/koordinat mana yang dipakai validasi jarak).
     */
    public function today(Request $request)
    {
        $user = $request->user('api');
        $store = $user->store;

        $attendance = Attendance::where('user_id', $user->id)
            ->where('date', Carbon::today()->toDateString())
            ->first();

        return response()->json([
            'success' => true,
            'attendance' => $attendance ? $this->transform($attendance) : null,
            'store' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
                'latitude' => $store->latitude,
                'longitude' => $store->longitude,
                'radius_meters' => $store->attendance_radius_meters ?? Attendance::DEFAULT_RADIUS_METERS,
            ] : null,
        ]);
    }

    /**
     * POST /api/staff/attendance/clock-in
     * Dipakai UNTUK ABSEN NORMAL saja — kasus device/wifi mati atau dinas
     * luar TIDAK lewat endpoint ini, itu dicatat admin manual lewat
     * AttendanceResource di Filament (lihat catatan migration
     * create_attendances_table).
     */
    public function clockIn(Request $request)
    {
        $request->validate([
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            // Dari LocationObject.mocked (Android only) — null kalau app
            // tidak kirim (mis. iOS, yang tidak punya info ini sama
            // sekali) atau versi app lama sebelum field ini ada.
            'is_mocked' => 'nullable|boolean',
        ]);

        $user = $request->user('api');
        $store = $user->store;

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini belum terhubung ke toko mana pun, tidak bisa absen.',
            ], 422);
        }

        try {
            $attendance = Attendance::clockIn($user, $store, (float) $request->latitude, (float) $request->longitude, $request->has('is_mocked') ? $request->boolean('is_mocked') : null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'attendance' => $this->transform($attendance)]);
    }

    /**
     * POST /api/staff/attendance/clock-out
     */
    public function clockOut(Request $request)
    {
        $request->validate([
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'is_mocked' => 'nullable|boolean',
        ]);

        $user = $request->user('api');

        try {
            $attendance = Attendance::clockOut($user, (float) $request->latitude, (float) $request->longitude, $request->has('is_mocked') ? $request->boolean('is_mocked') : null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'attendance' => $this->transform($attendance)]);
    }

    /**
     * GET /api/staff/attendance/history?month=2026-08
     */
    public function history(Request $request)
    {
        $request->validate(['month' => 'nullable|date_format:Y-m']);

        $month = $request->filled('month') ? Carbon::createFromFormat('Y-m', $request->month) : Carbon::now();

        $attendances = Attendance::where('user_id', $request->user('api')->id)
            ->whereYear('date', $month->year)
            ->whereMonth('date', $month->month)
            ->orderByDesc('date')
            ->get();

        return response()->json([
            'success' => true,
            'attendances' => $attendances->map(fn (Attendance $a) => $this->transform($a)),
            // Total menit telat bulan berjalan — acuan cepat buat app
            // tampilkan sisa toleransi tanpa staff harus jumlahkan manual.
            'total_late_minutes' => $attendances->sum('late_minutes'),
        ]);
    }

    /**
     * GET /api/staff/leave-requests
     */
    public function leaveRequestsIndex(Request $request)
    {
        $user = $request->user('api');
        $requests = LeaveRequest::where('user_id', $user->id)
            ->with('reviewer:id,name')
            ->orderByDesc('created_at')
            ->get();

        $year = Carbon::now()->year;

        return response()->json([
            'success' => true,
            'leave_requests' => $requests->map(fn (LeaveRequest $r) => $this->transformLeaveRequest($r)),
            // Kuota cuti tahun berjalan — cuma 'cuti' yang dipotong jatah
            // ini (Izin/Sakit tidak), lihat LeaveRequest::annualQuotaFor().
            'cuti_quota' => LeaveRequest::annualQuotaFor($user, $year),
            'cuti_used' => LeaveRequest::usedCutiDaysFor($user, $year),
        ]);
    }

    /**
     * POST /api/staff/leave-requests
     */
    public function leaveRequestsStore(Request $request)
    {
        $request->validate([
            'type'       => 'required|in:izin,sakit,cuti',
            // after_or_equal:today — dulu bisa ajukan tanggal lampau lewat
            // panggilan API langsung (app cuma mencegah lewat UI stepper,
            // bukan validasi sungguhan). Lihat audit modul Izin & Cuti
            // 2026-08-27.
            'start_date' => 'required|date|after_or_equal:today',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'reason'     => 'required|string|max:1000',
            // Opsional — mis. scan/foto surat dokter untuk 'sakit'. Belum
            // diwajibkan (lihat audit modul Izin & Cuti 2026-08-27).
            'document'   => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        $user = $request->user('api');

        if (! $user->store_id) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini belum terhubung ke toko mana pun, tidak bisa mengajukan izin.',
            ], 422);
        }

        $dayCount = Carbon::parse($request->start_date)->diffInDays(Carbon::parse($request->end_date)) + 1;

        if ($dayCount > LeaveRequest::MAX_DURATION_DAYS) {
            return response()->json([
                'success' => false,
                'message' => 'Durasi pengajuan maksimal ' . LeaveRequest::MAX_DURATION_DAYS . ' hari. Hubungi admin untuk kasus khusus di luar itu.',
            ], 422);
        }

        if (LeaveRequest::hasOverlap($user, $request->start_date, $request->end_date)) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah punya pengajuan izin/cuti lain yang tanggalnya tumpang tindih dengan rentang ini.',
            ], 422);
        }

        if ($request->type === 'cuti') {
            $remaining = LeaveRequest::remainingCutiFor($user, Carbon::parse($request->start_date)->year);
            if ($dayCount > $remaining) {
                return response()->json([
                    'success' => false,
                    'message' => "Sisa jatah cuti tahun ini tinggal {$remaining} hari, tidak cukup untuk {$dayCount} hari yang diajukan.",
                ], 422);
            }
        }

        $leaveRequest = LeaveRequest::create([
            'user_id'    => $user->id,
            'store_id'   => $user->store_id,
            'type'       => $request->type,
            'start_date' => $request->start_date,
            'end_date'   => $request->end_date,
            'reason'     => $request->reason,
            'document'   => $request->hasFile('document') ? $request->file('document')->store('leave-requests', 'public') : null,
            'status'     => 'pending',
        ]);

        return response()->json(['success' => true, 'leave_request' => $this->transformLeaveRequest($leaveRequest)], 201);
    }

    /**
     * POST /api/staff/leave-requests/{id}/cancel
     * Staff batalkan pengajuan MILIK SENDIRI selama masih 'pending' — beda
     * dari 'rejected' (keputusan admin), lihat catatan migration
     * enhance_leave_requests_table.
     */
    public function leaveRequestsCancel(Request $request, int $id)
    {
        $leaveRequest = LeaveRequest::where('id', $id)
            ->where('user_id', $request->user('api')->id)
            ->first();

        if (! $leaveRequest) {
            return response()->json(['success' => false, 'message' => 'Pengajuan tidak ditemukan.'], 404);
        }

        if ($leaveRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cuma pengajuan yang masih menunggu persetujuan yang bisa dibatalkan.',
            ], 422);
        }

        $leaveRequest->update(['status' => 'cancelled']);

        return response()->json(['success' => true, 'leave_request' => $this->transformLeaveRequest($leaveRequest)]);
    }

    private function transform(Attendance $attendance): array
    {
        return [
            'id' => $attendance->id,
            'date' => $attendance->date->toDateString(),
            'entry_type' => $attendance->entry_type,
            'clock_in_at' => $attendance->clock_in_at?->toIso8601String(),
            'clock_out_at' => $attendance->clock_out_at?->toIso8601String(),
            'clock_in_distance_meters' => $attendance->clock_in_distance_meters,
            'late_minutes' => $attendance->late_minutes,
            'early_leave_minutes' => $attendance->early_leave_minutes,
            'note' => $attendance->note,
        ];
    }

    private function transformLeaveRequest(LeaveRequest $r): array
    {
        return [
            'id' => $r->id,
            'request_number' => $r->request_number,
            'type' => $r->type,
            'start_date' => $r->start_date->toDateString(),
            'end_date' => $r->end_date->toDateString(),
            'reason' => $r->reason,
            'document_url' => $r->document ? \Illuminate\Support\Facades\Storage::disk('public')->url($r->document) : null,
            'status' => $r->status,
            'review_note' => $r->review_note,
            'reviewer_name' => $r->reviewer?->name,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
        ];
    }
}