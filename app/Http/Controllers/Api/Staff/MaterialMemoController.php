<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\ConsumableItem;
use App\Models\InventoryItem;
use App\Models\MaterialMemo;
use App\Models\MaterialMemoItem;
use App\Models\RawMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MaterialMemoController extends Controller
{
    /**
     * Sama pola dengan controller staff lain — dicek lewat "Akses Menu"
     * akun Filament staff (menu Memo Pengambilan/Pengembalian).
     */
    private function authorizeAccess(Request $request): bool
    {
        $user = $request->user('api');

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(\App\Filament\Resources\MaterialMemoResource::class);
    }

    /**
     * GET /api/staff/memos
     *
     * Company-wide untuk full-access, tapi staff biasa cuma lihat memo
     * toko sendiri — sama pola dengan storeId di InventoryController.
     */
    public function index(Request $request)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Memo.',
            ], 403);
        }

        $user = $request->user('api');
        $storeId = $user->isFullAccess() ? $request->query('store_id') : $user->store_id;

        $memos = MaterialMemo::query()
            ->with(['creator:id,name', 'store:id,name'])
            ->withCount('items')
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['success' => true, 'data' => $memos]);
    }

    /**
     * POST /api/staff/memos
     *
     * Bikin header memo dulu — barang ditambahkan belakangan lewat
     * addItem(), satu-satu, supaya stok langsung berkurang saat itu juga
     * (bukan ditunda sampai submit form penuh).
     */
    public function store(Request $request)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Memo.',
            ], 403);
        }

        $user = $request->user('api');

        $validator = Validator::make($request->all(), [
            'vehicle_info' => 'nullable|string|max:255',
            'spk_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            // Full-access wajib kirim store_id manual (tidak terikat
            // toko manapun) — staff biasa selalu pakai toko akunnya
            // sendiri, field ini diabaikan kalau dikirim.
            'store_id' => $user->isFullAccess() ? 'required|exists:stores,id' : 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $storeId = $user->isFullAccess() ? $request->store_id : $user->store_id;

        if (! $storeId) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini belum terhubung ke toko manapun.',
            ], 422);
        }

        $memo = DB::transaction(function () use ($request, $user, $storeId) {
            return MaterialMemo::create([
                'memo_number' => MaterialMemo::generateMemoNumber(),
                'store_id' => $storeId,
                'vehicle_info' => $request->vehicle_info,
                'spk_number' => $request->spk_number,
                'notes' => $request->notes,
                'created_by' => $user->id,
            ]);
        });

        $memo->load(['creator:id,name', 'store:id,name', 'items']);

        return response()->json([
            'success' => true,
            'message' => 'Memo berhasil dibuat.',
            'data' => $memo,
        ], 201);
    }

    /**
     * GET /api/staff/memos/{id}
     */
    public function show(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Memo.',
            ], 403);
        }

        $memo = MaterialMemo::with(['creator:id,name', 'store:id,name', 'items'])->find($id);

        if (! $memo) {
            return response()->json(['success' => false, 'message' => 'Memo tidak ditemukan.'], 404);
        }

        return response()->json(['success' => true, 'data' => $memo]);
    }

    /**
     * POST /api/staff/memos/{id}/items
     *
     * Tambah 1 baris barang ke memo — untuk Bahan Baku/Barang Habis Pakai
     * ini LANGSUNG mengurangi stok (recordMovement 'out'), sama seperti
     * kalau staff catat keluar manual satu-satu, cuma sekarang lewat 1
     * form. Untuk PPF/WF (inventory_item + kode gulungan), meter yang
     * dipakai LANGSUNG tercatat via recordUsage() — tidak ada konsep
     * "dikembalikan" untuk gulungan film.
     */
    public function addItem(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Memo.',
            ], 403);
        }

        $memo = MaterialMemo::find($id);

        if (! $memo) {
            return response()->json(['success' => false, 'message' => 'Memo tidak ditemukan.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'item_type' => 'required|in:raw_material,consumable_item,inventory_item',
            'item_id' => 'required|integer',
            'qty_taken' => 'required_if:item_type,raw_material,consumable_item|nullable|numeric|min:0.01',
            'meters_used' => 'required_if:item_type,inventory_item|nullable|numeric|min:0.01',
            'condition_notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = $request->user('api')->id;
        $note = "Memo {$memo->memo_number}" . ($request->condition_notes ? " — {$request->condition_notes}" : '');

        try {
            $memoItem = match ($request->item_type) {
                'raw_material' => $this->addMaterialItem(
                    RawMaterial::find($request->item_id),
                    'raw_material',
                    $memo,
                    (float) $request->qty_taken,
                    $userId,
                    $note,
                    $request->condition_notes
                ),
                'consumable_item' => $this->addMaterialItem(
                    ConsumableItem::find($request->item_id),
                    'consumable_item',
                    $memo,
                    (float) $request->qty_taken,
                    $userId,
                    $note,
                    $request->condition_notes
                ),
                'inventory_item' => $this->addInventoryItem(
                    InventoryItem::find($request->item_id),
                    $memo,
                    (float) $request->meters_used,
                    $userId,
                    $note,
                    $request->condition_notes
                ),
            };
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if ($memoItem instanceof \Illuminate\Http\JsonResponse) {
            return $memoItem;
        }

        $memo->load(['creator:id,name', 'store:id,name', 'items']);

        return response()->json([
            'success' => true,
            'message' => 'Barang berhasil ditambahkan ke memo.',
            'data' => $memo,
        ]);
    }

    /**
     * @param  RawMaterial|ConsumableItem|null  $material
     */
    private function addMaterialItem($material, string $itemType, MaterialMemo $memo, float $qtyTaken, int $userId, string $note, ?string $conditionNotes)
    {
        if (! $material) {
            return response()->json(['success' => false, 'message' => 'Barang tidak ditemukan.'], 404);
        }

        return DB::transaction(function () use ($material, $itemType, $memo, $qtyTaken, $userId, $note, $conditionNotes) {
            $material->recordMovement('out', $qtyTaken, $userId, $note);

            return MaterialMemoItem::create([
                'material_memo_id' => $memo->id,
                'item_type' => $itemType,
                'item_id' => $material->id,
                'item_name' => $material->name,
                'unit' => $material->unit,
                'qty_taken' => $qtyTaken,
                'condition_notes' => $conditionNotes,
            ]);
        });
    }

    private function addInventoryItem(?InventoryItem $item, MaterialMemo $memo, float $meters, int $userId, string $note, ?string $conditionNotes)
    {
        if (! $item) {
            return response()->json(['success' => false, 'message' => 'Barang tidak ditemukan.'], 404);
        }

        if (! $item->scroll_code_id) {
            return response()->json(['success' => false, 'message' => 'Barang ini tidak punya kode gulungan terkait.'], 422);
        }

        $scrollCode = \App\Models\ScrollCode::find($item->scroll_code_id);

        return DB::transaction(function () use ($scrollCode, $item, $memo, $meters, $userId, $note, $conditionNotes) {
            $scrollCode->recordUsage($meters, $userId, $note);

            return MaterialMemoItem::create([
                'material_memo_id' => $memo->id,
                'item_type' => 'inventory_item',
                'item_id' => $item->id,
                'item_name' => $item->name . ' (' . $scrollCode->code . ')',
                'unit' => 'meter',
                'meters_used' => $meters,
                'condition_notes' => $conditionNotes,
            ]);
        });
    }

    /**
     * POST /api/staff/memos/{id}/items/{itemId}/return
     *
     * Isi jumlah dikembalikan untuk 1 baris Bahan Baku/Barang Habis Pakai
     * — cuma boleh sekali per baris (belum pernah diisi sebelumnya).
     * Sisa terpakai (qty_used) dihitung otomatis, dan sisa yang balik ke
     * stok dicatat lewat recordMovement('in', ...).
     */
    public function returnItem(Request $request, int $id, int $itemId)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Memo.',
            ], 403);
        }

        $memo = MaterialMemo::find($id);

        if (! $memo) {
            return response()->json(['success' => false, 'message' => 'Memo tidak ditemukan.'], 404);
        }

        $memoItem = MaterialMemoItem::where('material_memo_id', $memo->id)->find($itemId);

        if (! $memoItem) {
            return response()->json(['success' => false, 'message' => 'Baris barang tidak ditemukan.'], 404);
        }

        if (! in_array($memoItem->item_type, ['raw_material', 'consumable_item'], true)) {
            return response()->json(['success' => false, 'message' => 'Jenis barang ini tidak punya alur pengembalian.'], 422);
        }

        if ($memoItem->qty_returned !== null) {
            return response()->json(['success' => false, 'message' => 'Pengembalian untuk baris ini sudah pernah dicatat.'], 422);
        }

        $validator = Validator::make($request->all(), [
            // 0 diperbolehkan — artinya semua yang diambil habis terpakai,
            // tidak ada yang balik ke stok.
            'qty_returned' => ['required', 'numeric', 'min:0', 'max:' . $memoItem->qty_taken],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $material = $memoItem->resolveItem();

        if (! $material) {
            return response()->json(['success' => false, 'message' => 'Barang aslinya sudah tidak ada di sistem.'], 404);
        }

        $qtyReturned = (float) $request->qty_returned;
        $userId = $request->user('api')->id;
        $note = "Memo {$memo->memo_number} — pengembalian";

        DB::transaction(function () use ($material, $memoItem, $qtyReturned, $userId, $note) {
            if ($qtyReturned > 0) {
                $material->recordMovement('in', $qtyReturned, $userId, $note);
            }

            $memoItem->update([
                'qty_returned' => $qtyReturned,
                'qty_used' => $memoItem->qty_taken - $qtyReturned,
            ]);
        });

        $memo->load(['creator:id,name', 'store:id,name', 'items']);

        return response()->json([
            'success' => true,
            'message' => 'Pengembalian berhasil dicatat.',
            'data' => $memo,
        ]);
    }
}
