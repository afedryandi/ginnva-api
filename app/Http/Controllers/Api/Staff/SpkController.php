<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Spk;
use App\Services\SpkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * API SPK untuk mobile app (diminta user 2026-09-16) -- supaya staff
 * bisa isi SPK langsung dari HP saat inspeksi kendaraan di lapangan,
 * bukan cuma lewat Filament di kantor. Semua tulis-menulis tetap lewat
 * SpkService yang sama dengan Filament (lihat SpkResource), jadi 2
 * pintu masuk (web admin & mobile) selalu konsisten.
 */
class SpkController extends Controller
{
    private function authorize(Request $request): bool
    {
        $user = $request->user('api');

        return (bool) ($user && ! $user->hasRole('partner') && ! $user->hasRole('installer'));
    }

    /**
     * GET /api/staff/spks
     */
    public function index(Request $request)
    {
        if (! $this->authorize($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke SPK.'], 403);
        }

        $user = $request->user('api');

        $query = Spk::query()->with(['booking:id,booking_number', 'store:id,name', 'damageMarks'])
            ->orderByDesc('created_at');

        if (! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        $spks = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $spks->items(),
            'meta' => [
                'current_page' => $spks->currentPage(),
                'last_page' => $spks->lastPage(),
                'total' => $spks->total(),
            ],
        ]);
    }

    /**
     * GET /api/staff/spks/checklist-template
     * Dipanggil mobile app pas buka form buat SPK BARU, supaya
     * daftar checklist bawaan (Spk::DEFAULT_CHECKLIST) tidak perlu
     * di-hardcode ulang di sisi mobile.
     */
    public function checklistTemplate(Request $request)
    {
        if (! $this->authorize($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke SPK.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => Spk::DEFAULT_CHECKLIST,
        ]);
    }

    /**
     * GET /api/staff/spks/{id}
     */
    public function show(Request $request, int $id)
    {
        if (! $this->authorize($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke SPK.'], 403);
        }

        $spk = Spk::with(['checklistItems', 'damageMarks', 'booking:id,booking_number', 'store:id,name'])->find($id);

        if (! $spk || ! $this->canAccess($request, $spk)) {
            return response()->json(['success' => false, 'message' => 'SPK tidak ditemukan.'], 404);
        }

        return response()->json(['success' => true, 'data' => $spk]);
    }

    /**
     * POST /api/staff/spks
     */
    public function store(Request $request)
    {
        if (! $this->authorize($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke SPK.'], 403);
        }

        $user = $request->user('api');

        $validator = Validator::make($request->all(), $this->validationRules());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $booking = Booking::find($request->booking_id);

        if (! $booking || $booking->status !== 'confirmed') {
            return response()->json(['success' => false, 'message' => 'Booking tidak valid atau belum dikonfirmasi.'], 422);
        }

        if (! $user->isFullAccess() && $booking->store_id !== $user->store_id) {
            return response()->json(['success' => false, 'message' => 'Booking ini milik toko lain.'], 403);
        }

        $data = $this->extractSpkData($request);
        $data['booking_id'] = $booking->id;
        $data['store_id'] = $booking->store_id;

        try {
            $spk = app(SpkService::class)->create(
                $data,
                $request->input('checklist_items', []),
                $user->id,
                $request->has('damage_marks') ? $request->input('damage_marks', []) : null
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'SPK berhasil disimpan.',
            'data' => $spk,
        ], 201);
    }

    /**
     * PUT /api/staff/spks/{id}
     */
    public function update(Request $request, int $id)
    {
        if (! $this->authorize($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke SPK.'], 403);
        }

        $spk = Spk::find($id);

        if (! $spk || ! $this->canAccess($request, $spk)) {
            return response()->json(['success' => false, 'message' => 'SPK tidak ditemukan.'], 404);
        }

        $rules = $this->validationRules();
        unset($rules['booking_id']); // booking_id tidak bisa diubah setelah SPK dibuat.

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $this->extractSpkData($request);

        try {
            $spk = app(SpkService::class)->update(
                $spk,
                $data,
                $request->input('checklist_items', []),
                $request->has('damage_marks') ? $request->input('damage_marks', []) : null
            );
        } catch (RuntimeException $e) {
            // Ditambahkan bareng gate tracks_batch (f28, 2026-09-24) --
            // SEBELUMNYA update() di sini tidak pernah bisa melempar
            // RuntimeException (SpkService::update() cuma bisa gagal
            // lewat store()/create() dulu), jadi try/catch ini memang
            // belum ada. Tanpa ini, staff yang coba selesaikan SPK lewat
            // app mobile tanpa Catat Pemakaian dulu akan dapat 500 error
            // mentah, bukan pesan yang jelas.
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'SPK berhasil diperbarui.',
            'data' => $spk,
        ]);
    }

    /**
     * PUT /api/staff/spks/{id}/damage-marks
     * Endpoint terpisah buat halaman "Kondisi Kendaraan" tersendiri di
     * mobile app (diminta user 2026-09-16: inspeksi kendaraan dibuka
     * sebagai halaman baru, bukan bagian dari form SPK utama) -- cuma
     * terima & simpan damage_marks, TIDAK butuh field SPK lain sama
     * sekali (beda dari update() yang mewajibkan customer_name dkk.).
     */
    public function updateDamageMarks(Request $request, int $id)
    {
        if (! $this->authorize($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke SPK.'], 403);
        }

        $spk = Spk::find($id);

        if (! $spk || ! $this->canAccess($request, $spk)) {
            return response()->json(['success' => false, 'message' => 'SPK tidak ditemukan.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'damage_marks' => 'present|array',
            'damage_marks.*.x_percent' => 'required|numeric|min:0|max:100',
            'damage_marks.*.y_percent' => 'required|numeric|min:0|max:100',
            'damage_marks.*.code' => 'required|in:C,B,P,G,M,OS',
            'damage_marks.*.note' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $spk = app(SpkService::class)->updateDamageMarksOnly($spk, $request->input('damage_marks', []));

        return response()->json([
            'success' => true,
            'message' => 'Kondisi kendaraan berhasil disimpan.',
            'data' => $spk,
        ]);
    }

    private function canAccess(Request $request, Spk $spk): bool
    {
        $user = $request->user('api');

        return $user->isFullAccess() || $spk->store_id === $user->store_id;
    }

    private function validationRules(): array
    {
        return [
            'booking_id' => 'required|integer|exists:bookings,id',
            'customer_name' => 'required|string|max:255',
            'phone_number' => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'vehicle_plate' => 'nullable|string|max:20',
            'vehicle_vin' => 'nullable|string|max:50',
            'vehicle_brand' => 'nullable|string|max:100',
            'vehicle_year' => 'nullable|string|max:4',
            'vehicle_type' => 'nullable|in:sedan,suv,mpv,jeep',
            'vehicle_km' => 'nullable|integer|min:0',
            'fuel_level' => 'nullable|in:e,quarter,half,three_quarter,f',
            'battery_note' => 'nullable|string|max:100',
            'checked_in_at' => 'nullable|date',
            'checked_out_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'checklist_items' => 'nullable|array',
            'checklist_items.*.category' => 'required_with:checklist_items|in:pekerjaan,extra_service,perlengkapan',
            'checklist_items.*.label' => 'required_with:checklist_items|string|max:255',
            'checklist_items.*.is_checked' => 'nullable|boolean',
            'damage_marks' => 'nullable|array',
            'damage_marks.*.x_percent' => 'required_with:damage_marks|numeric|min:0|max:100',
            'damage_marks.*.y_percent' => 'required_with:damage_marks|numeric|min:0|max:100',
            'damage_marks.*.code' => 'required_with:damage_marks|in:C,B,P,G,M,OS',
            'damage_marks.*.note' => 'nullable|string|max:255',
        ];
    }

    private function extractSpkData(Request $request): array
    {
        return $request->only([
            'customer_name',
            'phone_number',
            'address',
            'vehicle_plate',
            'vehicle_vin',
            'vehicle_brand',
            'vehicle_year',
            'vehicle_type',
            'vehicle_km',
            'fuel_level',
            'battery_note',
            'checked_in_at',
            'checked_out_at',
            'notes',
        ]);
    }
}
