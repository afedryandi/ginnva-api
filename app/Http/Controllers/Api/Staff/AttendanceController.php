<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\LeaveRequest;
use App\Models\Store;
use App\Services\AttendanceCorrectionService;
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
            // Fitur "Foto Selfie Absensi" (2026-09-27, diminta user) --
            // WAJIB, di ATAS validasi radius yang sudah ada (bukan
            // pengganti). Mobile app memaksa kamera langsung (bukan
            // galeri, lihat app/staff/attendance/index.tsx) -- TIDAK ada
            // deteksi wajah otomatis di sini (keputusan user), foto
            // murni bukti visual buat ditinjau manual admin.
            // dimensions ditambahkan 2026-09-27 (audit ulang, fitur foto)
            // -- SEBELUMNYA cuma image|max:5120, gambar 1x1 px valid MIME
            // tetap lolos, tidak berguna sebagai bukti visual yang bisa
            // ditinjau admin.
            'photo'     => 'required|image|max:5120|dimensions:min_width=200,min_height=200',
        ]);

        $user = $request->user('api');
        $store = $user->store;

        if (! $store) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini belum terhubung ke toko mana pun, tidak bisa absen.',
            ], 422);
        }

        $photoPath = $request->file('photo')->store('attendance-photos', 'public');

        // Bug diperbaiki 2026-09-27 (audit ulang, fitur foto) --
        // SEBELUMNYA cuma catch InvalidArgumentException (radius/mock),
        // exception LAIN (DB error, dst) menjalar sebagai 500 TANPA
        // pernah menghapus foto yang sudah ter-upload -- file yatim
        // menumpuk permanen di storage tanpa baris Attendance yang
        // mereferensikannya. catch(\Throwable) + rethrow menjamin foto
        // SELALU dihapus kalau clockIn() gagal, apapun jenis errornya.
        try {
            $attendance = Attendance::clockIn(
                $user,
                $store,
                (float) $request->latitude,
                (float) $request->longitude,
                $request->has('is_mocked') ? $request->boolean('is_mocked') : null,
                $photoPath
            );
        } catch (\InvalidArgumentException $e) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($photoPath);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($photoPath);

            throw $e;
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
            'photo'     => 'required|image|max:5120|dimensions:min_width=200,min_height=200',
        ]);

        $user = $request->user('api');
        $photoPath = $request->file('photo')->store('attendance-photos', 'public');

        try {
            $attendance = Attendance::clockOut(
                $user,
                (float) $request->latitude,
                (float) $request->longitude,
                $request->has('is_mocked') ? $request->boolean('is_mocked') : null,
                $photoPath
            );
        } catch (\InvalidArgumentException $e) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($photoPath);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($photoPath);

            throw $e;
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
     * GET /api/staff/attendance/corrections
     * Gap ditutup 2026-09-26 (audit Absensi Karyawan, "tidak ada jalur
     * pengajuan koreksi dari mobile app") -- riwayat pengajuan koreksi
     * MILIK SENDIRI, supaya staff tahu statusnya tanpa tanya admin.
     */
    public function correctionsIndex(Request $request)
    {
        $requests = AttendanceCorrectionRequest::where('user_id', $request->user('api')->id)
            ->with('reviewedBy:id,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'corrections' => $requests->map(fn (AttendanceCorrectionRequest $r) => $this->transformCorrection($r)),
        ]);
    }

    /**
     * POST /api/staff/attendance/corrections
     * Dibatasi entry_type='manual' saja (lupa absen masuk/keluar, isi
     * jam manual) -- jenis lain (Dinas Luar/Alpha/Izin) tetap khusus
     * admin lewat Filament (AttendanceCorrectionRequestResource), sama
     * seperti sebelumnya. Alur approval TIDAK berubah sama sekali --
     * cuma titik masuk baru ke AttendanceCorrectionService::submit()
     * yang sudah ada, dipakai juga oleh Filament.
     */
    public function correctionsStore(Request $request)
    {
        $request->validate([
            'date'         => 'required|date|before_or_equal:today',
            'clock_in_at'  => 'nullable|date_format:H:i',
            'clock_out_at' => 'nullable|date_format:H:i',
            'reason'       => 'required|string|max:1000',
        ]);

        if (! $request->filled('clock_in_at') && ! $request->filled('clock_out_at')) {
            return response()->json([
                'success' => false,
                'message' => 'Isi minimal salah satu: jam masuk atau jam keluar.',
            ], 422);
        }

        $user = $request->user('api');

        if (! $user->store_id) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini belum terhubung ke toko mana pun, tidak bisa mengajukan koreksi.',
            ], 422);
        }

        $date = Carbon::parse($request->date);
        $existingAttendance = Attendance::where('user_id', $user->id)->where('date', $date->toDateString())->first();

        $correctionRequest = app(AttendanceCorrectionService::class)->submit([
            'attendance_id' => $existingAttendance?->id,
            'user_id'       => $user->id,
            'store_id'      => $user->store_id,
            'date'          => $date->toDateString(),
            'entry_type'    => 'manual',
            'clock_in_at'   => $request->filled('clock_in_at') ? $date->copy()->setTimeFromTimeString($request->clock_in_at) : null,
            'clock_out_at'  => $request->filled('clock_out_at') ? $date->copy()->setTimeFromTimeString($request->clock_out_at) : null,
            'reason'        => $request->reason,
        ], $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan koreksi terkirim, menunggu persetujuan admin/store manager.',
            'correction' => $this->transformCorrection($correctionRequest),
        ], 201);
    }

    private function transformCorrection(AttendanceCorrectionRequest $r): array
    {
        return [
            'id'            => $r->id,
            'date'          => $r->date->toDateString(),
            'clock_in_at'   => $r->clock_in_at?->toIso8601String(),
            'clock_out_at'  => $r->clock_out_at?->toIso8601String(),
            'reason'        => $r->reason,
            'status'        => $r->status,
            'review_notes'  => $r->review_notes,
            'reviewer_name' => $r->reviewedBy?->name,
            'reviewed_at'   => $r->reviewed_at?->toIso8601String(),
            'created_at'    => $r->created_at->toIso8601String(),
        ];
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
            'clock_in_photo_url' => $attendance->clock_in_photo ? \Illuminate\Support\Facades\Storage::disk('public')->url($attendance->clock_in_photo) : null,
            'clock_out_photo_url' => $attendance->clock_out_photo ? \Illuminate\Support\Facades\Storage::disk('public')->url($attendance->clock_out_photo) : null,
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
