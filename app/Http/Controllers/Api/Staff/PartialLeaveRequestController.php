<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\PartialLeaveRequest;
use App\Services\PartialLeaveRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pengajuan Izin Sebagian Hari dari aplikasi staf (2026-11-18). Sama seperti absen, izin, dan dinas luar, terbuka untuk semua karyawan (tidak dibatasi
 * hasMenuAccess()): setiap karyawan hanya melihat dan mengajukan untuk dirinya sendiri. Aturan ada di PartialLeaveRequestService.
 */
class PartialLeaveRequestController extends Controller
{
    /** GET /api/staff/partial-leave-requests: riwayat pengajuan milik sendiri. */
    public function index(Request $request)
    {
        $requests = PartialLeaveRequest::withoutGlobalScopes()
            ->where('user_id', $request->user('api')->id)
            ->with('reviewedBy:id,name')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(60)
            ->get();

        return response()->json([
            'success' => true,
            'limits' => [
                'max_days_back' => PartialLeaveRequestService::MAX_DAYS_BACK,
                'max_days_ahead' => PartialLeaveRequestService::MAX_DAYS_AHEAD,
            ],
            'categories' => collect(PartialLeaveRequest::CATEGORY_LABELS)->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values(),
            'partial_leave_requests' => $requests->map(fn (PartialLeaveRequest $r) => $this->transform($r)),
        ]);
    }

    /** POST /api/staff/partial-leave-requests */
    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'category' => ['required', Rule::in(array_keys(PartialLeaveRequest::CATEGORY_LABELS))],
            // Terlambat Masuk Kerja dan Pulang Cepat memakai satu jam (time); Keluar Lingkungan Kantor memakai jam keluar dan jam kembali.
            'time' => ['nullable', 'date_format:H:i', 'required_if:category,' . PartialLeaveRequest::CATEGORY_LATE_ARRIVAL . ',' . PartialLeaveRequest::CATEGORY_EARLY_LEAVE],
            'start_time' => ['nullable', 'date_format:H:i', 'required_if:category,' . PartialLeaveRequest::CATEGORY_LEAVE_OFFICE],
            'end_time' => ['nullable', 'date_format:H:i', 'required_if:category,' . PartialLeaveRequest::CATEGORY_LEAVE_OFFICE],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $leave = app(PartialLeaveRequestService::class)->submit($request->user('api'), $data);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan izin sebagian hari terkirim, menunggu persetujuan.',
            'partial_leave_request' => $this->transform($leave->load('reviewedBy:id,name')),
        ], 201);
    }

    /** POST /api/staff/partial-leave-requests/{id}/cancel */
    public function cancel(Request $request, int $id)
    {
        $user = $request->user('api');
        $leave = PartialLeaveRequest::withoutGlobalScopes()->where('id', $id)->where('user_id', $user->id)->first();

        if (! $leave) {
            return response()->json(['success' => false, 'message' => 'Pengajuan tidak ditemukan.'], 404);
        }

        try {
            app(PartialLeaveRequestService::class)->cancel($leave, $user->id);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Pengajuan izin sebagian hari dibatalkan.']);
    }

    private function transform(PartialLeaveRequest $r): array
    {
        return [
            'id' => $r->id,
            'date' => $r->date->toDateString(),
            'category' => $r->category,
            'category_label' => $r->categoryLabel(),
            'window_label' => $r->windowLabel(),
            'start_time' => substr((string) $r->start_time, 0, 5),
            'end_time' => substr((string) $r->end_time, 0, 5),
            'reason' => $r->reason,
            'status' => $r->status,
            'review_notes' => $r->review_notes,
            'reviewer_name' => $r->reviewedBy?->name,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'created_at' => $r->created_at->toIso8601String(),
            'can_cancel' => app(PartialLeaveRequestService::class)->isCancellable($r),
        ];
    }
}
