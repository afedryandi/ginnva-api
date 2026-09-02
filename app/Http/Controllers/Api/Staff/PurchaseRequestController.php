<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\ConsumableItem;
use App\Models\PurchaseRequest;
use App\Models\RawMaterial;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Permohonan Pembelian mandiri dari mobile app — sebelumnya fitur ini
 * HANYA bisa diajukan lewat Filament (dashboard web admin), padahal
 * fitur inventaris lain (Memo, scan barang) semua sudah punya versi
 * mobile. Sama pola dengan MaterialMemoController: dicek lewat "Akses
 * Menu" akun Filament staff (menu Permohonan Pembelian, grup Inventaris),
 * BUKAN self-service tanpa batas seperti Absensi/Izin/Payroll/SP — fitur
 * ini memang di grup Inventaris, bukan Karyawan.
 *
 * Approve/Reject/Fulfill TETAP cuma lewat Filament (PurchaseRequestResource)
 * — tidak ada jalur mobile untuk itu, controller ini murni ajukan & lihat
 * status permohonan sendiri.
 *
 * Dibangun saat audit fitur Permohonan Pembelian.
 */
class PurchaseRequestController extends Controller
{
    private function authorizeAccess(Request $request): bool
    {
        $user = $request->user('api');

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(\App\Filament\Resources\PurchaseRequestResource::class);
    }

    private function forbiddenResponse()
    {
        return response()->json([
            'success' => false,
            'message' => 'Akun ini tidak punya akses ke menu Permohonan Pembelian.',
        ], 403);
    }

    /**
     * GET /api/staff/purchase-requests
     * Full-access lihat company-wide (opsional filter store_id), staff
     * biasa cuma lihat toko sendiri — sama pola dengan MaterialMemoController.
     */
    public function index(Request $request)
    {
        if (! $this->authorizeAccess($request)) {
            return $this->forbiddenResponse();
        }

        $user = $request->user('api');
        $storeId = $user->isFullAccess() ? $request->query('store_id') : $user->store_id;

        $requests = PurchaseRequest::query()
            ->with(['requester:id,name', 'reviewer:id,name'])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests->map(fn (PurchaseRequest $r) => $this->transform($r)),
        ]);
    }

    /**
     * POST /api/staff/purchase-requests
     * Sama pola snapshot nama/satuan dari katalog seperti
     * CreatePurchaseRequest (Filament) — item_id cuma dipakai untuk
     * lookup, nama/satuan yang benar-benar disimpan selalu snapshot dari
     * sumbernya, supaya tidak pernah beda dari nama asli kalau nama
     * katalog berubah belakangan.
     */
    public function store(Request $request)
    {
        if (! $this->authorizeAccess($request)) {
            return $this->forbiddenResponse();
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
            'quantity' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:1000',
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

        if ($request->item_type === 'raw_material') {
            $material = RawMaterial::find($request->item_id);
            if (! $material) {
                return response()->json(['success' => false, 'message' => 'Bahan baku tidak ditemukan.'], 404);
            }
            $itemName = $material->name;
            $unit = $material->unit;
        } elseif ($request->item_type === 'consumable_item') {
            $item = ConsumableItem::find($request->item_id);
            if (! $item) {
                return response()->json(['success' => false, 'message' => 'Barang habis pakai tidak ditemukan.'], 404);
            }
            $itemName = $item->name;
            $unit = $item->unit;
        }

        // request_number DIISI OTOMATIS lewat PurchaseRequest::booted()
        // (creating event) — sama seperti CreatePurchaseRequest di
        // Filament, tidak digenerate manual di sini. Tetap dibungkus
        // retry karena do-while cek-lalu-insert di dalam model itu tidak
        // atomik: 2 request nyaris bersamaan bisa lolos cek exists()
        // dengan nomor yang sama, baru ketahuan bentrok saat constraint
        // unique di DB menolak salah satunya (QueryException mentah).
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

        $purchaseRequest->load(['requester:id,name', 'reviewer:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Permohonan pembelian berhasil diajukan.',
            'data' => $this->transform($purchaseRequest),
        ], 201);
    }

    private function transform(PurchaseRequest $r): array
    {
        return [
            'id' => $r->id,
            'request_number' => $r->request_number,
            'item_type' => $r->item_type,
            'item_name' => $r->item_name,
            'unit' => $r->unit,
            'quantity' => (float) $r->quantity,
            'reason' => $r->reason,
            'status' => $r->status,
            'review_note' => $r->review_note,
            'requester_name' => $r->requester?->name,
            'reviewer_name' => $r->reviewer?->name,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'fulfilled_at' => $r->fulfilled_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
