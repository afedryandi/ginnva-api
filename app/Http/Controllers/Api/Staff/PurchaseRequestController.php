<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\ConsumableItem;
use App\Models\PurchaseRequest;
use App\Models\RawMaterial;
use App\Services\PurchaseRequestNotifier;
use App\Services\RestockRequestService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Permohonan Pembelian mandiri dari mobile app: ajukan, lihat status, dan batalkan permohonan sendiri selagi menunggu. Dicek lewat
 * "Akses Menu" akun Filament staff (menu Permohonan Pembelian), dan eksplisit (audit 2026-11-02): akun dengan akses bawaan tidak ikut.
 * Setujui/Tolak/Terpenuhi tetap hanya lewat Filament (PurchaseRequestResource).
 */
class PurchaseRequestController extends Controller
{
    private function authorizeAccess(Request $request): bool
    {
        return $request->user('api')?->hasPurchaseRequestAccess() ?? false;
    }

    private function forbiddenResponse()
    {
        return response()->json([
            'success' => false,
            'message' => 'Akun ini tidak punya akses ke menu Permohonan Pembelian.',
        ], 403);
    }

    private function canCreate(Request $request): bool
    {
        return $this->authorizeAccess($request)
            && $request->user('api')->hasModuleAction(\App\Filament\Resources\PurchaseRequestResource::class, 'create', false);
    }

    /**
     * GET /api/staff/purchase-requests
     * Full-access lihat company-wide (opsional filter store_id), staff biasa cuma lihat toko sendiri. Dipaginasi lewat offset.
     */
    public function index(Request $request)
    {
        if (! $this->authorizeAccess($request)) {
            return $this->forbiddenResponse();
        }

        $user = $request->user('api');
        $storeId = $user->isFullAccess() ? $request->query('store_id') : $user->store_id;
        $offset = max(0, (int) $request->query('offset', 0));
        $perPage = 50;

        $query = PurchaseRequest::query()
            ->with(['requester:id,name', 'reviewer:id,name'])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderByDesc('created_at');

        $requests = (clone $query)->offset($offset)->limit($perPage)->get();
        $hasMore = (clone $query)->offset($offset + $perPage)->limit(1)->exists();

        return response()->json([
            'success' => true,
            'data' => $requests->map(fn (PurchaseRequest $r) => $this->transform($r, $user->id)),
            'has_more' => $hasMore,
            'can_create' => $this->canCreate($request),
        ]);
    }

    /**
     * POST /api/staff/purchase-requests
     */
    public function store(Request $request)
    {
        if (! $this->authorizeAccess($request)) {
            return $this->forbiddenResponse();
        }

        if (! $this->canCreate($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini hanya boleh melihat permohonan pembelian, tidak boleh mengajukannya.',
            ], 403);
        }

        $user = $request->user('api');

        if (! $user->store_id && ! $user->isFullAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini belum terhubung ke toko mana pun.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'item_type' => 'required|in:raw_material,consumable_item,asset',
            'item_id' => 'required_if:item_type,raw_material,consumable_item|nullable|integer',
            'item_name' => 'required_if:item_type,asset|nullable|string|max:255',
            'quantity' => 'required|numeric|min:0.01|max:1000000',
            'reason' => 'nullable|string|max:1000',
            'store_id' => 'nullable|integer|exists:stores,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $itemName = $request->item_name;
        $unit = null;
        $catalogItem = null;

        if ($request->item_type === 'raw_material') {
            $catalogItem = RawMaterial::find($request->item_id);
            if (! $catalogItem) {
                return response()->json(['success' => false, 'message' => 'Bahan baku tidak ditemukan.'], 404);
            }
        } elseif ($request->item_type === 'consumable_item') {
            $catalogItem = ConsumableItem::find($request->item_id);
            if (! $catalogItem) {
                return response()->json(['success' => false, 'message' => 'Barang habis pakai tidak ditemukan.'], 404);
            }
        }

        if ($catalogItem) {
            if ($catalogItem->is_active === false) {
                return response()->json(['success' => false, 'message' => "{$catalogItem->name} sudah dinonaktifkan dan tidak bisa diminta beli."], 422);
            }

            // Satu barang satu permohonan terbuka (sama seperti tombol Minta Beli): mencegah dua orang mengajukan barang yang sama.
            if ($open = app(RestockRequestService::class)->openRequest($catalogItem)) {
                return response()->json([
                    'success' => false,
                    'message' => "Sudah ada permohonan untuk {$catalogItem->name} ({$open->request_number}, status " . ($open->status === 'approved' ? 'disetujui' : 'menunggu') . ').',
                ], 422);
            }

            $itemName = $catalogItem->name;
            $unit = $catalogItem->unit;
        }

        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                $purchaseRequest = DB::transaction(function () use ($request, $user, $itemName, $unit) {
                    return PurchaseRequest::create([
                        'store_id' => $user->isFullAccess() ? ($request->store_id ?? $user->store_id) : $user->store_id,
                        'item_type' => $request->item_type,
                        'item_id' => in_array($request->item_type, ['raw_material', 'consumable_item']) ? $request->item_id : null,
                        'item_name' => $itemName,
                        'unit' => $unit,
                        'quantity' => $request->quantity,
                        'reason' => $request->reason,
                        'status' => 'pending',
                        'requested_by' => $user->id,
                    ]);
                });
                break;
            } catch (QueryException $e) {
                if ($attempts >= 3 || ! str_contains($e->getMessage(), 'request_number')) {
                    throw $e;
                }
            }
        }

        app(PurchaseRequestNotifier::class)->notifyNew($purchaseRequest);

        $purchaseRequest->load(['requester:id,name', 'reviewer:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Permohonan pembelian berhasil diajukan.',
            'data' => $this->transform($purchaseRequest, $user->id),
        ], 201);
    }

    /**
     * POST /api/staff/purchase-requests/{id}/cancel
     *
     * Pemohon membatalkan permohonannya sendiri selagi masih menunggu (salah jumlah atau salah barang). Akses penuh bisa membatalkan yang mana pun.
     */
    public function cancel(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return $this->forbiddenResponse();
        }

        $user = $request->user('api');
        $purchaseRequest = PurchaseRequest::query()->find($id);

        if (! $purchaseRequest || (! $user->isFullAccess() && (int) $purchaseRequest->store_id !== (int) $user->store_id)) {
            return response()->json(['success' => false, 'message' => 'Permohonan tidak ditemukan.'], 404);
        }

        if (! $user->isFullAccess() && (int) $purchaseRequest->requested_by !== (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'Hanya pemohon yang bisa membatalkan permohonannya.'], 403);
        }

        $processed = DB::transaction(function () use ($purchaseRequest) {
            $locked = PurchaseRequest::query()->whereKey($purchaseRequest->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                return $locked->status;
            }

            $locked->update(['status' => 'cancelled']);

            return null;
        });

        if ($processed !== null) {
            return response()->json(['success' => false, 'message' => 'Permohonan ini sudah diproses, jadi tidak bisa dibatalkan lagi.'], 422);
        }

        $purchaseRequest->refresh()->load(['requester:id,name', 'reviewer:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Permohonan dibatalkan.',
            'data' => $this->transform($purchaseRequest, $user->id),
        ]);
    }

    private function transform(PurchaseRequest $r, ?int $userId = null): array
    {
        return [
            'id' => $r->id,
            'request_number' => $r->request_number,
            'item_type' => $r->item_type,
            'item_name' => $r->item_name,
            'unit' => $r->unit,
            'quantity' => (float) $r->quantity,
            'quantity_approved' => $r->quantity_approved !== null ? (float) $r->quantity_approved : null,
            'reason' => $r->reason,
            'status' => $r->status,
            'review_note' => $r->review_note,
            'requester_name' => $r->requester?->name,
            'reviewer_name' => $r->reviewer?->name,
            'is_mine' => $userId !== null && (int) $r->requested_by === $userId,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'fulfilled_at' => $r->fulfilled_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
