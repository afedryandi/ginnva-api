<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\OvertimeRequest;
use App\Services\OvertimeRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Pengajuan Lembur dari aplikasi staf (2026-11-12). Sama seperti absen dan izin, terbuka untuk semua karyawan (tidak dibatasi hasMenuAccess()):
 * setiap karyawan hanya melihat dan mengajukan lembur untuk dirinya sendiri. Aturan pengajuan dan persetujuan ada di OvertimeRequestService.
 */
class OvertimeRequestController extends Controller
{
    /** GET /api/staff/overtime-requests: riwayat pengajuan milik sendiri. */
    public function index(Request $request)
    {
        $requests = OvertimeRequest::withoutGlobalScopes()
            ->where('user_id', $request->user('api')->id)
            ->with(['booking:id,booking_number', 'reviewedBy:id,name'])
            ->orderByDesc('starts_at')
            ->limit(60)
            ->get();

        return response()->json([
            'success' => true,
            'limits' => [
                'min_minutes' => OvertimeRequestService::MIN_MINUTES,
                'max_hours_per_day' => OvertimeRequestService::MAX_MINUTES_PER_DAY / 60,
                'max_hours_per_week' => OvertimeRequestService::MAX_MINUTES_PER_WEEK / 60,
                'max_days_ahead' => OvertimeRequestService::MAX_DAYS_AHEAD,
            ],
            'overtime_requests' => $requests->map(fn (OvertimeRequest $r) => $this->transform($r)),
        ]);
    }

    /** POST /api/staff/overtime-requests */
    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'booking_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user('api');
        $date = Carbon::parse($data['date']);

        try {
            $overtime = app(OvertimeRequestService::class)->submit($user, [
                'starts_at' => $date->copy()->setTimeFromTimeString($data['start_time']),
                'ends_at' => $date->copy()->setTimeFromTimeString($data['end_time']),
                'booking_id' => $data['booking_id'] ?? null,
                'description' => $data['description'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan lembur terkirim, menunggu persetujuan store manager atau admin. Lembur baru boleh dikerjakan setelah disetujui.',
            'overtime_request' => $this->transform($overtime->load(['booking:id,booking_number', 'reviewedBy:id,name'])),
        ], 201);
    }

    /** POST /api/staff/overtime-requests/{id}/cancel */
    public function cancel(Request $request, int $id)
    {
        $user = $request->user('api');
        $overtime = OvertimeRequest::withoutGlobalScopes()->where('id', $id)->where('user_id', $user->id)->first();

        if (! $overtime) {
            return response()->json(['success' => false, 'message' => 'Pengajuan tidak ditemukan.'], 404);
        }

        try {
            app(OvertimeRequestService::class)->cancel($overtime, $user->id);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Pengajuan lembur dibatalkan.']);
    }

    /**
     * GET /api/staff/overtime-requests/bookings?search=...
     * Pilihan Booking toko karyawan untuk ditautkan sebagai pekerjaan lembur: yang masih berjalan (menunggu atau dikonfirmasi), paling banyak 20.
     */
    public function bookings(Request $request)
    {
        $user = $request->user('api');

        if (! $user->store_id) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $search = trim((string) $request->query('search', ''));

        $bookings = Booking::withoutGlobalScopes()
            ->where('store_id', $user->store_id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('booking_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('vehicle_plate', 'like', "%{$search}%")))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'booking_number', 'customer_name', 'vehicle_brand', 'vehicle_model', 'vehicle_plate', 'status']);

        return response()->json(['success' => true, 'data' => $bookings]);
    }

    private function transform(OvertimeRequest $r): array
    {
        return [
            'id' => $r->id,
            'date' => $r->date->toDateString(),
            'starts_at' => $r->starts_at->toIso8601String(),
            'ends_at' => $r->ends_at->toIso8601String(),
            'minutes' => $r->minutes,
            'booking_id' => $r->booking_id,
            'booking_number' => $r->booking?->booking_number,
            'description' => $r->description,
            'status' => $r->status,
            'review_notes' => $r->review_notes,
            'reviewer_name' => $r->reviewedBy?->name,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'created_at' => $r->created_at->toIso8601String(),
            // Boleh ditarik kembali: masih menunggu, atau disetujui tetapi belum dimulai.
            'can_cancel' => $r->isPending() || ($r->isApproved() && $r->starts_at->greaterThan(now())),
        ];
    }
}
