<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Spk;
use App\Models\User;
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
    /**
     * Installer DIBUKA 2026-09-25 (audit SPK, keputusan user langsung:
     * "baca + isi, tidak bisa hapus/pindah booking") -- SEBELUMNYA
     * diblokir total (403) sama seperti partner, padahal SPK justru
     * dibuat untuk "isi form inspeksi kendaraan langsung dari HP di
     * lapangan" (lihat docblock class ini), pekerjaan yang secara fisik
     * dilakukan installer. Tidak ada endpoint hapus SPK sama sekali di
     * controller mobile ini (hapus cuma ada lewat Filament DeleteAction,
     * installer tidak akses Filament), dan booking_id sudah terkunci
     * untuk SEMUA role (lihat unset($rules['booking_id']) di update()) --
     * jadi cukup buka authorize() di sini, scoping "cuma SPK booking yang
     * ditugaskan ke installer itu" ditegakkan per-method lewat
     * canAccessAsInstaller() (index/show/update/updateDamageMarks) &
     * pengecekan eksplisit di store().
     */
    private function authorize(Request $request): bool
    {
        $user = $request->user('api');

        if (! $user || $user->hasRole('partner')) {
            return false;
        }

        // Akses menu SPK (keputusan 2026-10-06, konsisten dengan Booking): staf
        // non-installer wajib punya menu SPK; installer dikecualikan karena
        // mengisi SPK adalah bagian pekerjaannya (scoping ke booking miliknya
        // tetap ditegakkan per-method).
        return $user->hasSpkAccess();
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

        $query = Spk::query()->with(['booking:id,booking_number', 'store:id,name', 'damageMarks', 'installers:id,name'])
            ->orderByDesc('created_at');

        // Installer HANYA lihat SPK dari booking yang dirinya ditugaskan
        // sebagai installer, SPK manual yang ia buat, dan SPK manual yang
        // menugaskannya -- bukan seluruh SPK tokonya (beda dari
        // store_manager dkk.), sama pola scoping dengan
        // BookingController::index() untuk role ini.
        if ($user->hasRole('installer')) {
            $query->where(fn ($q) => $q->where('created_by', $user->id)
                ->orWhereHas('installers', fn ($i) => $i->where('users.id', $user->id))
                ->orWhereHas('booking.installers', fn ($i) => $i->where('users.id', $user->id)));
        } elseif (! $user->isFullAccess()) {
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

        $spk = Spk::with(['checklistItems', 'damageMarks', 'booking:id,booking_number', 'store:id,name', 'installers:id,name', 'materialMemos:id,spk_id,memo_number,flow,request_status'])->find($id);

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

        // SPK baru dibuat installer (untuk booking yang ditugaskan padanya), leader installer, dan atasannya (2026-11-17).
        // Staf lain tetap bisa melihat / melengkapi SPK sesuai aksesnya.
        if (! $user->canCreateSpk()) {
            return response()->json(['success' => false, 'message' => 'Hanya installer, leader installer, dan atasannya (store manager, direksi) yang bisa membuat SPK baru.'], 403);
        }

        // spk_number: nomor SPK manual (opsional, unik); kosong = nomor otomatis. Hanya saat membuat.
        $validator = Validator::make($request->all(), $this->validationRules() + ['spk_number' => 'nullable|string|max:100|unique:spks,spk_number']);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $installerIds = null;

        if ($request->filled('booking_id')) {
            $booking = Booking::with('installers')->find($request->booking_id);

            if (! $booking || $booking->status !== 'confirmed') {
                return response()->json(['success' => false, 'message' => 'Booking tidak valid atau belum dikonfirmasi.'], 422);
            }

            if ($user->hasRole('installer')) {
                if (! $booking->installers->contains('id', $user->id)) {
                    return response()->json(['success' => false, 'message' => 'Booking ini tidak ditugaskan ke akun ini.'], 403);
                }
            } elseif (! $user->isFullAccess() && $booking->store_id !== $user->store_id) {
                return response()->json(['success' => false, 'message' => 'Booking ini milik toko lain.'], 403);
            }

            $bookingId = $booking->id;
            $storeId = $booking->store_id;
        } else {
            // SPK manual (tanpa booking, 2026-11-17): toko akun pembuat; akses penuh tanpa toko wajib memilih store_id.
            $bookingId = null;
            $storeId = $user->isFullAccess() && $request->filled('store_id') ? (int) $request->input('store_id') : $user->store_id;

            if (! $storeId) {
                return response()->json(['success' => false, 'message' => 'Pilih toko untuk SPK ini.'], 422);
            }

            if ($request->has('installer_ids')) {
                $installerIds = $this->validInstallerIds((array) $request->input('installer_ids'), (int) $storeId);

                if ($installerIds === null) {
                    return response()->json(['success' => false, 'message' => 'Semua installer harus berasal dari toko SPK ini dan berstatus aktif.'], 422);
                }
            }
        }

        $data = $this->extractSpkData($request);
        $data['booking_id'] = $bookingId;
        $data['store_id'] = $storeId;
        $data['spk_number'] = $request->input('spk_number');

        if ($installerIds !== null) {
            $data['installer_ids'] = $installerIds;
        }

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

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $this->extractSpkData($request);

        // booking_id: SPK manual boleh ditautkan ke booking SEKALI (belakangan); SPK yang sudah terikat tidak bisa dipindah.
        if ($request->filled('booking_id')) {
            if ($spk->booking_id !== null) {
                if ((int) $request->booking_id !== (int) $spk->booking_id) {
                    return response()->json(['success' => false, 'message' => 'SPK ini sudah terikat ke booking dan tidak bisa dipindah.'], 422);
                }
            } else {
                $booking = Booking::with('installers')->find($request->booking_id);
                $user = $request->user('api');

                if (! $booking || $booking->status !== 'confirmed') {
                    return response()->json(['success' => false, 'message' => 'Booking tidak valid atau belum dikonfirmasi.'], 422);
                }

                if ($booking->store_id !== $spk->store_id) {
                    return response()->json(['success' => false, 'message' => 'Booking ini milik toko lain.'], 422);
                }

                if ($user->hasRole('installer') && ! $booking->installers->contains('id', $user->id)) {
                    return response()->json(['success' => false, 'message' => 'Booking ini tidak ditugaskan ke akun ini.'], 403);
                }

                if (Spk::where('booking_id', $booking->id)->exists()) {
                    return response()->json(['success' => false, 'message' => 'Booking ini sudah punya SPK.'], 422);
                }

                $data['booking_id'] = $booking->id;
            }
        }

        // Installer SPK manual (hanya selama belum terikat booking; setelah itu installer booking yang berlaku).
        if ($spk->booking_id === null && $request->has('installer_ids')) {
            $installerIds = $this->validInstallerIds((array) $request->input('installer_ids'), (int) $spk->store_id);

            if ($installerIds === null) {
                return response()->json(['success' => false, 'message' => 'Semua installer harus berasal dari toko SPK ini dan berstatus aktif.'], 422);
            }

            $data['installer_ids'] = $installerIds;
        }

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

        if ($user->hasRole('installer')) {
            return $spk->isAccessibleToInstaller($user);
        }

        return $user->isFullAccess() || $spk->store_id === $user->store_id;
    }

    /**
     * GET /api/staff/spks/assignable-installers?store_id=
     * Pilihan installer untuk SPK manual: installer aktif di toko akun ini (akses penuh boleh memilih toko lewat store_id).
     */
    public function assignableInstallers(Request $request)
    {
        if (! $this->authorize($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke SPK.'], 403);
        }

        $user = $request->user('api');
        $storeId = $user->isFullAccess() && $request->filled('store_id') ? (int) $request->input('store_id') : $user->store_id;

        $installers = User::where('store_id', $storeId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'installer'))
            ->whereDoesntHave('technician', fn ($q) => $q->whereIn('status', ['pending_review', 'inactive']))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['success' => true, 'data' => $installers]);
    }

    /**
     * Installer SPK manual harus berasal dari toko SPK dan berstatus aktif di roster (sama dengan penugasan installer booking).
     * Mengembalikan daftar id unik, atau null kalau ada yang tidak valid.
     *
     * @return list<int>|null
     */
    private function validInstallerIds(array $ids, int $storeId): ?array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $valid = User::whereIn('id', $ids)
            ->where('store_id', $storeId)
            ->whereHas('roles', fn ($q) => $q->where('name', 'installer'))
            ->whereDoesntHave('technician', fn ($q) => $q->whereIn('status', ['pending_review', 'inactive']))
            ->count();

        return $valid === count($ids) ? $ids : null;
    }

    private function validationRules(): array
    {
        return [
            'booking_id' => 'nullable|integer|exists:bookings,id',
            'store_id' => 'nullable|integer|exists:stores,id',
            'installer_ids' => 'nullable|array',
            'installer_ids.*' => 'integer|exists:users,id',
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
