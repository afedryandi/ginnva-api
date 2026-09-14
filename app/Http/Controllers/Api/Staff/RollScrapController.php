<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\RollScrapPool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * "Sisa Roll" — diminta 2026-09-14, sisi mobile dari fitur yang sama
 * dengan RollScrapPoolResource (Filament). Lihat catatan lengkap di
 * RollScrapPool model. Dibatasi ke staff yang akun Filament-nya
 * dicentang akses menu "Produk PPF/WF" (InventoryItemResource) —
 * sama gate dengan InventoryController, karena fitur ini nempel di
 * alur kerja yang sama (installer yang pasang film/PPF).
 */
class RollScrapController extends Controller
{
    private function authorize_(Request $request): bool
    {
        $user = $request->user('api');

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(\App\Filament\Resources\InventoryItemResource::class);
    }

    private function canActOnPool(RollScrapPool $pool, $user): bool
    {
        return ($user?->isFullAccess() ?? false) || $pool->store_id === $user?->store_id;
    }

    /**
     * GET /api/staff/roll-scraps?search=...
     *
     * Pool sisa toko staff sendiri (full-access lihat semua toko) —
     * dipakai saat installer mau cek/pakai sisa roll sebelum buka roll
     * baru. Cuma yang masih ada sisanya (>0) yang ditampilkan.
     */
    public function index(Request $request)
    {
        if (! $this->authorize_($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Inventaris.',
            ], 403);
        }

        $user = $request->user('api');
        $search = trim((string) $request->query('search', ''));

        // "Kode Gulungan" (diminta 2026-09-14) -- pool sisa gabungan dari
        // >1 roll, staff perlu tahu kode ASAL sisanya (mis. buat cocokkan
        // sama catatan garansi/riwayat). Diambil dari movement type='in'
        // (lihat RollScrapPool::collectFrom()) -- 'out'/'correction' tidak
        // relevan, tidak punya source_scroll_code_id.
        $pools = RollScrapPool::query()
            ->with([
                'store:id,name',
                'filmProduct:id,sku,name',
                'movements' => fn ($q) => $q->where('type', 'in')->with('sourceScrollCode:id,code'),
            ])
            ->where('remaining_length_meters', '>', 0)
            ->when(! $user->isFullAccess(), fn ($q) => $q->where('store_id', $user->store_id))
            ->when($search !== '', fn ($q) => $q->whereHas('filmProduct', fn ($fq) => $fq
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")))
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get()
            ->each(function (RollScrapPool $pool) {
                $pool->setAttribute('scroll_codes', $pool->movements
                    ->pluck('sourceScrollCode.code')
                    ->filter()
                    ->unique()
                    ->values());
                $pool->unsetRelation('movements');
            });

        return response()->json([
            'success' => true,
            'data' => $pools,
        ]);
    }

    /**
     * POST /api/staff/roll-scraps/{pool}/consume
     *
     * Catat pemakaian sekian meter dari pool sisa — dipakai installer
     * saat instalasi memakai sisaan, bukan roll baru. $booking_id
     * opsional.
     */
    public function consume(Request $request, RollScrapPool $pool)
    {
        if (! $this->authorize_($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Inventaris.',
            ], 403);
        }

        $user = $request->user('api');

        if (! $this->canActOnPool($pool, $user)) {
            return response()->json([
                'success' => false,
                'message' => 'Pool sisa ini milik toko lain.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'meters' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:500',
            'booking_id' => 'nullable|exists:bookings,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $pool->consume((float) $request->meters, $user->id, $request->note, $request->booking_id);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pemakaian sisa roll dicatat.',
            'data' => $pool->fresh(['store:id,name', 'filmProduct:id,sku,name']),
        ]);
    }
}
