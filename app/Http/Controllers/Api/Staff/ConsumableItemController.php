<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\ConsumableItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Sama persis pola RawMaterialController — Barang Habis Pakai TIDAK
 * punya kode fisik per unit, jadi dicari lewat nama/kode, bukan scan QR.
 */
class ConsumableItemController extends Controller
{
    private function authorizeAccess(Request $request): bool
    {
        $user = $request->user('api');

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(\App\Filament\Resources\ConsumableItemResource::class);
    }

    /**
     * GET /api/staff/consumables?search=...
     */
    public function index(Request $request)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Barang Habis Pakai.',
            ], 403);
        }

        $search = trim((string) $request->query('search', ''));

        $items = ConsumableItem::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'name', 'code', 'category', 'unit', 'current_stock', 'reorder_point']);

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    /**
     * GET /api/staff/consumables/{id}
     */
    public function show(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Barang Habis Pakai.',
            ], 403);
        }

        $item = ConsumableItem::with(['movements' => fn ($q) => $q->with('user:id,name')->limit(20)])->find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Barang tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $item,
            // Dipakai app buat munculkan tombol "Muat Riwayat Lainnya" —
            // lihat movements() untuk endpoint paginasinya. Sama pola
            // dengan InventoryController::show().
            'movements_has_more' => $item->movements()->count() > $item->movements->count(),
        ]);
    }

    /**
     * GET /api/staff/consumables/{id}/movements?offset=20
     *
     * "Muat Riwayat Lainnya" — SEBELUMNYA tidak ada jalan sama sekali
     * untuk melihat riwayat di luar 20 baris terakhir dari app, cuma bisa
     * lewat Filament. Sama pola dengan InventoryController::movements().
     */
    public function movements(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Barang Habis Pakai.',
            ], 403);
        }

        $item = ConsumableItem::find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Barang tidak ditemukan.',
            ], 404);
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $limit = 20;

        $movements = $item->movements()
            ->with('user:id,name')
            ->latest()
            ->offset($offset)
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $movements,
            'has_more' => $item->movements()->count() > $offset + $movements->count(),
        ]);
    }

    /**
     * POST /api/staff/consumables/{id}/movement
     */
    public function storeMovement(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Barang Habis Pakai.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:in,out',
            'quantity' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:500',
            // Opsional, cuma relevan untuk type=in — lihat
            // ConsumableItem::recordMovement(). Tidak ada UI-nya di
            // mobile (harga tetap tugas admin/Filament), API-nya
            // disiapkan supaya konsisten dengan RawMaterialController.
            'unit_cost' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $item = ConsumableItem::find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Barang tidak ditemukan.',
            ], 404);
        }

        try {
            $item->recordMovement(
                $request->type,
                (float) $request->quantity,
                $request->user('api')->id,
                $request->note,
                $request->filled('unit_cost') ? (float) $request->unit_cost : null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // ->fresh() SAJA tidak ikut memuat relasi movements — sama gotcha
        // dengan InventoryController::storeMovement()/RawMaterialController::storeMovement(),
        // makanya ->load() dipanggil lagi setelahnya di sini.
        return response()->json([
            'success' => true,
            'message' => $request->type === 'in' ? 'Barang masuk berhasil dicatat.' : 'Barang keluar berhasil dicatat.',
            'data' => $item->fresh()->load(['movements' => fn ($q) => $q->with('user:id,name')->limit(20)]),
        ]);
    }

    /**
     * POST /api/staff/consumables/{id}/adjust
     *
     * Stock opname — staff input hasil hitung fisik SEBENARNYA, sistem
     * yang hitung selisihnya sendiri (lihat ConsumableItem::adjustStock()).
     * Sebelumnya cuma ada di Filament, staff yang opname langsung di
     * lapangan lewat HP harus hitung selisihnya sendiri manual pakai
     * "Catat Masuk/Keluar" biasa — fitur ini menghilangkan langkah itu.
     */
    public function adjustStock(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Barang Habis Pakai.',
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

        $item = ConsumableItem::find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Barang tidak ditemukan.',
            ], 404);
        }

        $movement = $item->adjustStock((float) $request->actual_quantity, $request->user('api')->id, $request->note);

        return response()->json([
            'success' => true,
            'message' => $movement === null
                ? 'Hasil hitung fisik sama dengan stok di sistem — tidak ada penyesuaian yang dicatat.'
                : 'Stok disesuaikan (selisih ' . ($movement->quantity > 0 ? '+' : '') . $movement->quantity . ' ' . $item->unit . ').',
            'data' => $item->fresh()->load(['movements' => fn ($q) => $q->with('user:id,name')->limit(20)]),
        ]);
    }
}