<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\RawMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RawMaterialController extends Controller
{
    /**
     * Sama pola dengan InventoryController::authorizeScan() — dicek
     * lewat "Akses Menu" akun Filament staff (menu Bahan Baku).
     */
    private function authorizeAccess(Request $request): bool
    {
        $user = $request->user('api');

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(\App\Filament\Resources\RawMaterialResource::class);
    }

    /**
     * GET /api/staff/materials?search=...
     *
     * Bahan baku TIDAK punya kode fisik per unit (beda dari Barang/Aset
     * yang bisa di-scan QR) — jadi di app dicari lewat nama, bukan scan.
     * Dibatasi 20 hasil per pencarian, cukup untuk daftar pilih di app.
     */
    public function index(Request $request)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Bahan Baku.',
            ], 403);
        }

        $search = trim((string) $request->query('search', ''));

        // Batch aktif (quantity>0) ikut di-eager-load supaya staff bisa
        // lihat status kedaluwarsa langsung dari daftar pencarian — dulu
        // index() ini sama sekali tidak mengirim info kedaluwarsa, staff
        // harus buka detail 1-1 untuk tahu. Dihitung dari koleksi yang
        // sudah dimuat (bukan panggil RawMaterial::earliestActiveExpiryDate()
        // per baris), supaya tidak N+1 query untuk 30 baris hasil.
        $materials = RawMaterial::query()
            ->select(['id', 'name', 'code', 'category', 'unit', 'current_stock', 'reorder_point'])
            ->with(['batches' => fn ($q) => $q->where('quantity', '>', 0)->whereNotNull('expiry_date')])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(30)
            ->get()
            ->map(function (RawMaterial $m) {
                $earliest = $m->batches->min('expiry_date');

                $m->setAttribute('nearest_expiry_date', $earliest);
                $m->setAttribute('is_expired', $earliest !== null && \Illuminate\Support\Carbon::parse($earliest)->isPast());
                $m->setAttribute('is_near_expiry', $earliest !== null && \Illuminate\Support\Carbon::parse($earliest)->lte(now()->addDays(30)));

                return $m->makeHidden('batches');
            });

        return response()->json([
            'success' => true,
            'data' => $materials,
        ]);
    }

    /**
     * GET /api/staff/materials/{id}
     */
    public function show(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Bahan Baku.',
            ], 403);
        }

        $material = RawMaterial::with([
            'movements' => fn ($q) => $q->with('user:id,name')->limit(20),
            'batches' => fn ($q) => $q->where('quantity', '>', 0),
        ])->find($id);

        if (! $material) {
            return response()->json([
                'success' => false,
                'message' => 'Bahan baku tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $material,
            // Dipakai app buat munculkan tombol "Muat Riwayat Lainnya" —
            // lihat movements() untuk endpoint paginasinya. SEBELUMNYA
            // modul ini satu-satunya dari 3 modul quantity-based/movement
            // (Inventory, Consumables, Materials) yang tidak punya
            // paginasi riwayat di mobile sama sekali.
            'movements_has_more' => $material->movements()->count() > $material->movements->count(),
        ]);
    }

    /**
     * GET /api/staff/materials/{id}/movements?offset=20
     *
     * "Muat Riwayat Lainnya" — sama pola dengan
     * InventoryController::movements()/ConsumableItemController::movements().
     */
    public function movements(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Bahan Baku.',
            ], 403);
        }

        $material = RawMaterial::find($id);

        if (! $material) {
            return response()->json([
                'success' => false,
                'message' => 'Bahan baku tidak ditemukan.',
            ], 404);
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $limit = 20;

        $movements = $material->movements()
            ->with('user:id,name')
            ->latest()
            ->offset($offset)
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $movements,
            'has_more' => $material->movements()->count() > $offset + $movements->count(),
        ]);
    }

    /**
     * POST /api/staff/materials/{id}/movement
     *
     * Catat masuk/keluar — BEDA dari inventory (Barang), di sini WAJIB
     * ada jumlah karena bahan baku dilacak per kuantitas, bukan per unit.
     */
    public function storeMovement(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Bahan Baku.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:in,out',
            'quantity' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:500',
            // Cuma relevan untuk type=in — dipakai jadi tanggal batch baru
            // (lihat RawMaterial::recordMovement()). Boleh kosong (default
            // hari ini, tanpa kedaluwarsa) untuk staff yang catat cepat.
            'received_date' => 'nullable|date|before_or_equal:today',
            'expiry_date' => 'nullable|date',
            // Opsional, cuma relevan untuk type=in — harga beli batch ini
            // sendiri (untuk valuasi stok), lihat RawMaterial::recordMovement().
            'unit_cost' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $material = RawMaterial::find($id);

        if (! $material) {
            return response()->json([
                'success' => false,
                'message' => 'Bahan baku tidak ditemukan.',
            ], 404);
        }

        try {
            $material->recordMovement(
                $request->type,
                (float) $request->quantity,
                $request->user('api')->id,
                $request->note,
                $request->received_date,
                $request->expiry_date,
                $request->filled('unit_cost') ? (float) $request->unit_cost : null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // ->fresh() saja tidak ikut memuat relasi movements (lihat
        // perbaikan sama di InventoryController::storeMovement()) — app
        // saat ini fetch ulang setelah sukses jadi belum sampai crash,
        // tapi disamakan di sini juga supaya konsisten & aman kalau nanti
        // app dipakai langsung dari respons ini.
        $material = $material->fresh();
        $material->load([
            'movements' => fn ($q) => $q->with('user:id,name')->limit(20),
            'batches' => fn ($q) => $q->where('quantity', '>', 0),
        ]);

        return response()->json([
            'success' => true,
            'message' => $request->type === 'in' ? 'Bahan masuk berhasil dicatat.' : 'Bahan keluar berhasil dicatat.',
            'data' => $material,
        ]);
    }

    /**
     * POST /api/staff/materials/{id}/adjust
     *
     * Stock opname — sama tujuannya dengan tombol "Sesuaikan Stok" di
     * Filament (RawMaterial::adjustStock()), ditambahkan di sini juga
     * supaya staff yang opname langsung di lapangan lewat HP tidak perlu
     * hitung selisihnya sendiri manual pakai "Catat Masuk/Keluar" biasa.
     */
    public function adjustStock(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Bahan Baku.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'actual_quantity' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $material = RawMaterial::find($id);

        if (! $material) {
            return response()->json([
                'success' => false,
                'message' => 'Bahan baku tidak ditemukan.',
            ], 404);
        }

        $movement = $material->adjustStock((float) $request->actual_quantity, $request->user('api')->id, $request->note);

        $material = $material->fresh();
        $material->load([
            'movements' => fn ($q) => $q->with('user:id,name')->limit(20),
            'batches' => fn ($q) => $q->where('quantity', '>', 0),
        ]);

        return response()->json([
            'success' => true,
            'message' => $movement === null
                ? 'Hasil hitung fisik sama dengan stok di sistem — tidak ada penyesuaian yang dicatat.'
                : 'Stok disesuaikan (selisih ' . ($movement->quantity > 0 ? '+' : '') . $movement->quantity . ' ' . $material->unit . ').',
            'data' => $material,
        ]);
    }
}