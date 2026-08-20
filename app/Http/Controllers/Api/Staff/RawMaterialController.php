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

        // withCount HARUS dipasang sebelum select kolom eksplisit —
        // ->get($columns) menimpa seluruh select query (termasuk subquery
        // count dari withCount) kalau dipanggil belakangan.
        $materials = RawMaterial::query()
            ->select(['id', 'name', 'code', 'category', 'unit', 'current_stock', 'reorder_point'])
            ->withCount(['batches as active_batches_count' => fn ($q) => $q->where('quantity', '>', 0)])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(20)
            ->get();

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
        ])
            ->withCount(['batches as active_batches_count' => fn ($q) => $q->where('quantity', '>', 0)])
            ->find($id);

        if (! $material) {
            return response()->json([
                'success' => false,
                'message' => 'Bahan baku tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $material,
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
            // 1 nama bahan bisa datang banyak botol/wadah sekaligus —
            // quantity dibagi rata jadi $container_count batch terpisah
            // (lihat RawMaterial::recordMovement()). Default 1 kalau
            // tidak dikirim.
            'container_count' => 'nullable|integer|min:1',
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
                (int) ($request->container_count ?? 1),
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
        $material->loadCount(['batches as active_batches_count' => fn ($q) => $q->where('quantity', '>', 0)]);

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
        $material->loadCount(['batches as active_batches_count' => fn ($q) => $q->where('quantity', '>', 0)]);

        return response()->json([
            'success' => true,
            'message' => $movement === null
                ? 'Hasil hitung fisik sama dengan stok di sistem — tidak ada penyesuaian yang dicatat.'
                : 'Stok disesuaikan (selisih ' . ($movement->quantity > 0 ? '+' : '') . $movement->quantity . ' ' . $material->unit . ').',
            'data' => $material,
        ]);
    }
}
