<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\FieldDutyRequest;
use App\Services\FieldDutyRequestService;
use Illuminate\Http\Request;

/**
 * Pengajuan Dinas Luar dari aplikasi staf (2026-11-12). Sama seperti absen, izin, dan lembur, terbuka untuk semua karyawan (tidak dibatasi
 * hasMenuAccess()): setiap karyawan hanya melihat dan mengajukan untuk dirinya sendiri. Aturan ada di FieldDutyRequestService.
 */
class FieldDutyRequestController extends Controller
{
    /** GET /api/staff/field-duty-requests: riwayat pengajuan milik sendiri. */
    public function index(Request $request)
    {
        $requests = FieldDutyRequest::withoutGlobalScopes()
            ->where('user_id', $request->user('api')->id)
            ->with('reviewedBy:id,name')
            ->orderByDesc('date')
            ->limit(60)
            ->get();

        return response()->json([
            'success' => true,
            'limits' => [
                'max_days_back' => FieldDutyRequestService::MAX_DAYS_BACK,
                'max_days_ahead' => FieldDutyRequestService::MAX_DAYS_AHEAD,
                'min_partial_minutes' => FieldDutyRequestService::MIN_PARTIAL_MINUTES,
            ],
            'field_duty_requests' => $requests->map(fn (FieldDutyRequest $r) => $this->transform($r)),
        ]);
    }

    /** POST /api/staff/field-duty-requests */
    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            // Kosong keduanya = sehari penuh; terisi keduanya = dinas sebagian hari.
            'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time'],
            'description' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $duty = app(FieldDutyRequestService::class)->submit($request->user('api'), $data);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan dinas luar terkirim, menunggu persetujuan.',
            'field_duty_request' => $this->transform($duty->load('reviewedBy:id,name')),
        ], 201);
    }

    /** POST /api/staff/field-duty-requests/{id}/cancel */
    public function cancel(Request $request, int $id)
    {
        $user = $request->user('api');
        $duty = FieldDutyRequest::withoutGlobalScopes()->where('id', $id)->where('user_id', $user->id)->first();

        if (! $duty) {
            return response()->json(['success' => false, 'message' => 'Pengajuan tidak ditemukan.'], 404);
        }

        try {
            app(FieldDutyRequestService::class)->cancel($duty, $user->id);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'Pengajuan dinas luar dibatalkan.']);
    }

    /**
     * POST /api/staff/field-duty-requests/{id}/location-photo (multipart: photo)
     * Foto bukti saat tiba di lokasi dinas; wajib supaya dinas dianggap hadir. Lihat FieldDutyRequestService::attachLocationPhoto().
     */
    public function locationPhoto(Request $request, int $id)
    {
        $request->validate(['photo' => ['required', 'image', 'max:5120', 'dimensions:min_width=200,min_height=200']]);

        $user = $request->user('api');
        $duty = FieldDutyRequest::withoutGlobalScopes()->where('id', $id)->where('user_id', $user->id)->first();

        if (! $duty) {
            return response()->json(['success' => false, 'message' => 'Pengajuan tidak ditemukan.'], 404);
        }

        try {
            $duty = app(FieldDutyRequestService::class)->attachLocationPhoto($duty, $user, $request->file('photo'));
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Foto di lokasi tersimpan. Dinas luar Anda tercatat hadir.',
            'field_duty_request' => $this->transform($duty->load('reviewedBy:id,name')),
        ]);
    }

    private function transform(FieldDutyRequest $r): array
    {
        return [
            'id' => $r->id,
            'date' => $r->date->toDateString(),
            'is_full_day' => ! $r->isPartialDay(),
            'start_time' => $r->isPartialDay() ? substr($r->start_time, 0, 5) : null,
            'end_time' => $r->isPartialDay() ? substr($r->end_time, 0, 5) : null,
            'description' => $r->description,
            'status' => $r->status,
            'review_notes' => $r->review_notes,
            'reviewer_name' => $r->reviewedBy?->name,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'created_at' => $r->created_at->toIso8601String(),
            'can_cancel' => app(FieldDutyRequestService::class)->isCancellable($r),
            'location_photo_url' => \App\Support\SensitiveFiles::url($r->location_photo),
            'location_photo_at' => $r->location_photo_at?->toIso8601String(),
            // Foto bukti di lokasi wajib di hari dinas: aplikasi menampilkan tombol ambil foto saat ini true.
            'needs_location_photo' => $r->needsLocationPhoto(),
        ];
    }
}
