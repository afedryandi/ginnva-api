<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InventoryController extends Controller
{
    /**
     * Cuma staff yang di akun Filament-nya dicentang akses menu "Barang"
     * (InventoryItemResource) yang boleh scan — SENGAJA dicek lewat
     * hasMenuAccess() yang sama dengan yang dipakai Filament, bukan role
     * hardcode, supaya admin cukup atur 1 tempat (checklist "Akses Menu"
     * di form User) untuk keduanya sekaligus.
     */
    private function authorizeScan(Request $request): bool
    {
        $user = $request->user('api');

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(\App\Filament\Resources\InventoryItemResource::class);
    }

    /**
     * GET /api/staff/inventory/{code}
     *
     * Dipanggil begitu staff selesai scan QR di app mobile — cari barang
     * berdasarkan kode unik yang ter-encode di QR, sekalian kembalikan
     * riwayat keluar-masuk terakhir (20 baris) supaya staff bisa lihat
     * konteks sebelum mencatat transaksi baru.
     */
    public function show(Request $request, string $code)
    {
        if (! $this->authorizeScan($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Inventaris.',
            ], 403);
        }

        $item = InventoryItem::where('code', $code)
            ->with(['movements' => fn ($q) => $q->with('user:id,name')->limit(20)])
            ->first();

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Barang dengan kode ini tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $item,
        ]);
    }

    /**
     * POST /api/staff/inventory/{code}/movement
     *
     * Catat 1 kejadian barang keluar/masuk untuk kardus dengan kode ini.
     * Tidak ada kuantitas — 1 kardus = 1 unit, jadi cukup tipe (in/out) +
     * catatan opsional. Transisi status yang tidak masuk akal (mis. catat
     * "masuk" untuk barang yang sudah in_stock) divalidasi di
     * InventoryItem::recordMovement().
     */
    public function storeMovement(Request $request, string $code)
    {
        if (! $this->authorizeScan($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Inventaris.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:in,out',
            'note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $item = InventoryItem::where('code', $code)->first();

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Barang dengan kode ini tidak ditemukan.',
            ], 404);
        }

        try {
            $item->recordMovement($request->type, $request->user('api')->id, $request->note);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $request->type === 'in' ? 'Barang masuk berhasil dicatat.' : 'Barang keluar berhasil dicatat.',
            'data' => $item->fresh(),
        ]);
    }
}
