<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\ConsumableItem;
use App\Models\InventoryItem;
use App\Models\MaterialMemo;
use App\Models\MaterialMemoItem;
use App\Models\RawMaterial;
use App\Services\MaterialMemoStockService;
use Illuminate\Database\QueryException;
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
        // Eksplisit (bukan akses bawaan): akun dengan menu_access kosong tidak ikut (audit Memo, 2026-11-02).
        return $request->user('api')?->hasMaterialMemoAccess() ?? false;
    }

    /**
     * Sama pola authorizeAccess() TAPI tambah cek toko — staff biasa cuma
     * boleh sentuh memo TOKONYA SENDIRI. Sebelumnya cek ini cuma ada di
     * index(), semua endpoint lain (show/addItem/returnItem/updateItem/
     * destroyItem) bisa disentuh staff toko lain asal tahu/tebak ID-nya —
     * lubang kebocoran data lintas toko.
     */
    private function authorizeMemoAccess(Request $request, MaterialMemo $memo): bool
    {
        if (! $this->authorizeAccess($request)) {
            return false;
        }

        $user = $request->user('api');

        return $user->isFullAccess() || $memo->store_id === $user->store_id;
    }

    private function forbiddenResponse()
    {
        return response()->json([
            'success' => false,
            'message' => 'Akun ini tidak punya akses ke menu Memo.',
        ], 403);
    }

    /**
     * GET /api/staff/memos
     *
     * Company-wide untuk full-access, tapi staff biasa cuma lihat memo
     * toko sendiri — sama pola dengan storeId di InventoryController.
     * Dipaginasi lewat offset (bukan cuma limit polos) supaya toko yang
     * memo-nya sudah banyak (>50) tetap bisa buka yang lebih lama.
     */
    /** Membuat memo butuh izin Tambah; mengubah memo dan barangnya butuh izin Ubah pada menu Memo. */
    private function can(Request $request, string $action): bool
    {
        return $this->authorizeAccess($request)
            && $request->user('api')->hasModuleAction(\App\Filament\Resources\MaterialMemoResource::class, $action, false);
    }

    private function denyUnlessCan(Request $request, string $action): ?\Illuminate\Http\JsonResponse
    {
        if (! $this->can($request, $action)) {
            return response()->json(['success' => false, 'message' => 'Akun ini hanya boleh melihat memo, tidak boleh membuat atau mengubahnya.'], 403);
        }

        return null;
    }

    /**
     * Alasan sebuah booking tidak boleh ditautkan ke memo (null = boleh): harus ada, milik toko memo, belum selesai/dibatalkan, dan belum
     * tertaut ke memo lain. Tautan ke booking opsional; sistem tidak mewajibkannya.
     */
    private function bookingProblem(int $bookingId, int $storeId, ?int $ignoreMemoId = null): ?string
    {
        $booking = \App\Models\Booking::query()->find($bookingId);

        if (! $booking) {
            return 'Booking tidak ditemukan.';
        }

        if ((int) $booking->store_id !== $storeId) {
            return 'Booking ini milik toko lain.';
        }

        if (! in_array($booking->status, ['pending', 'confirmed'], true)) {
            return 'Booking ini sudah selesai atau dibatalkan.';
        }

        $taken = MaterialMemo::withoutGlobalScopes()
            ->where('booking_id', $bookingId)
            ->when($ignoreMemoId, fn ($q) => $q->where('id', '!=', $ignoreMemoId))
            ->exists();

        return $taken ? 'Booking ini sudah tertaut ke memo lain.' : null;
    }

    private function bookingErrorResponse(string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => ['booking_id' => [$message]]], 422);
    }

    /**
     * GET /api/staff/memos/bookings?search=...&store_id=...
     *
     * Pilihan booking untuk ditautkan ke memo: hanya milik toko akun ini (akses penuh: toko yang dipilih lewat store_id), yang belum
     * selesai atau dibatalkan, dan yang belum punya memo.
     */
    public function bookings(Request $request)
    {
        if (! $this->authorizeAccess($request)) {
            return $this->forbiddenResponse();
        }

        if (! $this->can($request, 'create') && ! $this->can($request, 'update')) {
            return $this->denyUnlessCan($request, 'update');
        }

        $user = $request->user('api');
        $storeId = $user->isFullAccess() ? (int) $request->query('store_id') : (int) $user->store_id;

        if (! $storeId) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $search = trim((string) $request->query('search', ''));

        $bookings = \App\Models\Booking::query()
            ->where('store_id', $storeId)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereNotIn('id', MaterialMemo::withoutGlobalScopes()->whereNotNull('booking_id')->select('booking_id'))
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('booking_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('vehicle_plate', 'like', "%{$search}%")))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'booking_number', 'customer_name', 'vehicle_brand', 'vehicle_model', 'vehicle_plate', 'status']);

        return response()->json(['success' => true, 'data' => $bookings]);
    }

    public function index(Request $request)
    {
        if (! $this->authorizeAccess($request)) {
            return $this->forbiddenResponse();
        }

        $user = $request->user('api');
        $storeId = $user->isFullAccess() ? $request->query('store_id') : $user->store_id;
        $offset = max(0, (int) $request->query('offset', 0));
        $perPage = 50;
        $search = trim((string) $request->query('search', ''));

        $query = MaterialMemo::query()
            ->with(['creator:id,name', 'store:id,name', 'booking:id,booking_number,customer_name'])
            ->withCount(['items', 'items as unreturned_count' => fn ($q) => $q->whereIn('item_type', ['raw_material', 'consumable_item'])->whereNull('qty_returned')])
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('memo_number', 'like', "%{$search}%")
                ->orWhere('vehicle_info', 'like', "%{$search}%")
                ->orWhere('spk_number', 'like', "%{$search}%")))
            ->when($request->boolean('unreturned'), fn ($q) => $q->hasUnreturned())
            ->orderByDesc('created_at');

        $memos = (clone $query)->offset($offset)->limit($perPage)->get();
        $hasMore = (clone $query)->offset($offset + $perPage)->limit(1)->exists();

        return response()->json([
            'success' => true,
            'data' => $memos,
            'has_more' => $hasMore,
            // Jumlah yang cocok dengan pencarian/filter, dan ringkasan seluruh memo (tidak terpengaruh pencarian/filter) untuk chip di app.
            'total' => (clone $query)->reorder()->count(),
            'summary' => [
                'all' => MaterialMemo::query()->when($storeId, fn ($q) => $q->where('store_id', $storeId))->count(),
                'unreturned' => MaterialMemo::query()->when($storeId, fn ($q) => $q->where('store_id', $storeId))->hasUnreturned()->count(),
            ],
            'can_create' => $this->can($request, 'create'),
        ]);
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
            return $this->forbiddenResponse();
        }

        if ($denied = $this->denyUnlessCan($request, 'create')) {
            return $denied;
        }

        $user = $request->user('api');

        $validator = Validator::make($request->all(), [
            'vehicle_info' => 'nullable|string|max:255',
            'spk_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            // Full-access wajib kirim store_id manual (tidak terikat
            // toko manapun) — staff biasa selalu pakai toko akunnya
            // sendiri, field ini diabaikan kalau dikirim.
            'booking_id' => 'nullable|integer',
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

        // generateMemoNumber() hitung-lalu-format tidak aman kalau 2
        // request nyaris bersamaan menghasilkan nomor yang sama persis —
        // constraint unique di DB bakal menolak salah satunya dengan
        // QueryException mentah. Coba ulang beberapa kali dengan nomor
        // baru daripada 500 error ke user.
        if ($request->filled('booking_id') && ($problem = $this->bookingProblem((int) $request->booking_id, (int) $storeId))) {
            return $this->bookingErrorResponse($problem);
        }

        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                $memo = DB::transaction(function () use ($request, $user, $storeId) {
                    return MaterialMemo::create([
                        'memo_number' => MaterialMemo::generateMemoNumber(),
                        'store_id' => $storeId,
                        'vehicle_info' => $request->vehicle_info,
                        'spk_number' => $request->spk_number,
                        'booking_id' => $request->filled('booking_id') ? (int) $request->booking_id : null,
                        'notes' => $request->notes,
                        'created_by' => $user->id,
                    ]);
                });
                break;
            } catch (QueryException $e) {
                if ($attempts >= 3 || ! str_contains($e->getMessage(), 'memo_number')) {
                    throw $e;
                }
            }
        }

        $memo->load(['creator:id,name', 'store:id,name', 'items', 'booking:id,booking_number,customer_name']);

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
        $memo = MaterialMemo::with(['creator:id,name', 'store:id,name', 'items', 'booking:id,booking_number,customer_name'])->find($id);

        if (! $memo) {
            return response()->json(['success' => false, 'message' => 'Memo tidak ditemukan.'], 404);
        }

        if (! $this->authorizeMemoAccess($request, $memo)) {
            return $this->forbiddenResponse();
        }

        return response()->json(['success' => true, 'data' => $memo, 'can_modify' => $this->can($request, 'update')]);
    }

    /**
     * PATCH /api/staff/memos/{id}
     *
     * Koreksi info kendaraan/SPK/catatan setelah memo dibuat — sebelumnya
     * cuma bisa lewat Filament, staff lapangan mentok kalau salah ketik.
     */
    public function update(Request $request, int $id)
    {
        $memo = MaterialMemo::find($id);

        if (! $memo) {
            return response()->json(['success' => false, 'message' => 'Memo tidak ditemukan.'], 404);
        }

        if (! $this->authorizeMemoAccess($request, $memo)) {
            return $this->forbiddenResponse();
        }

        if ($denied = $this->denyUnlessCan($request, 'update')) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'vehicle_info' => 'nullable|string|max:255',
            'spk_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'booking_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Tautan booking diperiksa hanya bila berubah (booking lama yang sudah selesai tidak menghalangi mengubah info lain).
        if ($request->filled('booking_id') && (int) $request->booking_id !== (int) $memo->booking_id
            && ($problem = $this->bookingProblem((int) $request->booking_id, (int) $memo->store_id, $memo->id))) {
            return $this->bookingErrorResponse($problem);
        }

        $memo->update($validator->validated());
        $memo->load(['creator:id,name', 'store:id,name', 'items', 'booking:id,booking_number,customer_name']);

        return response()->json([
            'success' => true,
            'message' => 'Info memo berhasil diperbarui.',
            'data' => $memo,
        ]);
    }

    /**
     * DELETE /api/staff/memos/{id}
     *
     * Hapus memo UTUH — semua barisnya dibalik dulu lewat
     * MaterialMemoStockService (stok/sisa meter dikembalikan penuh),
     * baru memonya (dan baris-barisnya, lewat cascade) dihapus. Beda
     * dari DeleteAction bawaan Filament yang sebelumnya cascade-delete
     * langsung tanpa lewat pembalikan ini sama sekali.
     */
    public function destroy(Request $request, int $id)
    {
        $memo = MaterialMemo::with('items')->find($id);

        if (! $memo) {
            return response()->json(['success' => false, 'message' => 'Memo tidak ditemukan.'], 404);
        }

        if (! $this->authorizeMemoAccess($request, $memo)) {
            return $this->forbiddenResponse();
        }

        if ($denied = $this->denyUnlessCan($request, 'update')) {
            return $denied;
        }

        // Hapus memo utuh hanya untuk akses penuh (keputusan 2026-10-09), sama dengan tombol Hapus di panel admin
        // (EditMaterialMemo): staf biasa cukup mengoreksi / menghapus baris barang di dalam memo.
        if (! $request->user('api')->isFullAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya akun akses penuh yang boleh menghapus memo utuh. Hapus baris barang yang salah, atau hubungi admin.',
            ], 403);
        }

        $userId = $request->user('api')->id;

        DB::transaction(function () use ($memo, $userId) {
            MaterialMemoStockService::reverseAllItems($memo, $userId);
            $memo->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Memo berhasil dihapus, stok/sisa meter yang sudah terpakai dikembalikan.',
        ]);
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
        $memo = MaterialMemo::find($id);

        if (! $memo) {
            return response()->json(['success' => false, 'message' => 'Memo tidak ditemukan.'], 404);
        }

        if (! $this->authorizeMemoAccess($request, $memo)) {
            return $this->forbiddenResponse();
        }

        if ($denied = $this->denyUnlessCan($request, 'update')) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            // Barang Habis Pakai ditolak saat dimatikan saklar fitur, walau klien lama masih menawarkannya.
            'item_type' => 'required|in:'.implode(',', \App\Support\HiddenInventoryTypes::allowed(['raw_material', 'consumable_item', 'inventory_item'])),
            'item_id' => 'required|integer',
            'qty_taken' => 'required_if:item_type,raw_material,consumable_item|nullable|numeric|min:0.01|max:1000000',
            'meters_used' => 'required_if:item_type,inventory_item|nullable|numeric|min:0.01|max:100000',
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

        try {
            match ($request->item_type) {
                'raw_material' => $this->requireMaterial(RawMaterial::find($request->item_id), fn ($material) => MaterialMemoStockService::addMaterial(
                    $material, 'raw_material', $memo, (float) $request->qty_taken, $userId, $request->condition_notes
                )),
                'consumable_item' => $this->requireMaterial(ConsumableItem::find($request->item_id), fn ($material) => MaterialMemoStockService::addMaterial(
                    $material, 'consumable_item', $memo, (float) $request->qty_taken, $userId, $request->condition_notes
                )),
                'inventory_item' => $this->requireMaterial(InventoryItem::find($request->item_id), fn ($item) => MaterialMemoStockService::addInventory(
                    $item, $memo, (float) $request->meters_used, $userId, $request->condition_notes
                )),
            };
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }

        $memo->load(['creator:id,name', 'store:id,name', 'items', 'booking:id,booking_number,customer_name']);

        return response()->json([
            'success' => true,
            'message' => 'Barang berhasil ditambahkan ke memo.',
            'data' => $memo,
        ]);
    }

    /**
     * @template T
     * @param  T|null  $model
     * @param  callable(T): mixed  $callback
     */
    private function requireMaterial($model, callable $callback)
    {
        if (! $model) {
            throw new \DomainException('Barang tidak ditemukan.');
        }

        return $callback($model);
    }

    /**
     * POST /api/staff/memos/{id}/items/{itemId}/return
     */
    public function returnItem(Request $request, int $id, int $itemId)
    {
        $memo = MaterialMemo::find($id);

        if (! $memo) {
            return response()->json(['success' => false, 'message' => 'Memo tidak ditemukan.'], 404);
        }

        if (! $this->authorizeMemoAccess($request, $memo)) {
            return $this->forbiddenResponse();
        }

        if ($denied = $this->denyUnlessCan($request, 'update')) {
            return $denied;
        }

        $memoItem = MaterialMemoItem::where('material_memo_id', $memo->id)->find($itemId);

        if (! $memoItem) {
            return response()->json(['success' => false, 'message' => 'Baris barang tidak ditemukan.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'qty_returned' => ['required', 'numeric', 'min:0', 'max:1000000'],
            // Hanya dipakai untuk bahan baku (batch baru hasil pengembalian); diabaikan untuk jenis lain.
            'expiry_date' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            MaterialMemoStockService::returnMaterial(
                $memoItem,
                (float) $request->qty_returned,
                $request->user('api')->id,
                $memo,
                null,
                $request->filled('expiry_date') ? (string) $request->expiry_date : null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $memo->load(['creator:id,name', 'store:id,name', 'items', 'booking:id,booking_number,customer_name']);

        return response()->json([
            'success' => true,
            'message' => 'Pengembalian berhasil dicatat.',
            'data' => $memo,
        ]);
    }

    /**
     * PATCH /api/staff/memos/{id}/items/{itemId}
     *
     * Koreksi jumlah 1 baris yang salah input — BUKAN ganti barangnya
     * (kalau salah pilih barang, hapus baris lewat destroyItem() lalu
     * tambah ulang). Cuma selisihnya yang disesuaikan ke stok/sisa meter
     * gulungan, bukan menimpa polos.
     */
    /**
     * POST /api/staff/memos/{id}/items/{itemId}/correct-return
     *
     * Koreksi pengembalian yang sudah tercatat (alasan wajib); stok disesuaikan sebesar selisihnya.
     */
    public function correctReturn(Request $request, int $id, int $itemId)
    {
        $memo = MaterialMemo::find($id);

        if (! $memo) {
            return response()->json(['success' => false, 'message' => 'Memo tidak ditemukan.'], 404);
        }

        if (! $this->authorizeMemoAccess($request, $memo)) {
            return $this->forbiddenResponse();
        }

        if ($denied = $this->denyUnlessCan($request, 'update')) {
            return $denied;
        }

        $memoItem = MaterialMemoItem::where('material_memo_id', $memo->id)->find($itemId);

        if (! $memoItem) {
            return response()->json(['success' => false, 'message' => 'Baris barang tidak ditemukan.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'qty_returned' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'note' => ['required', 'string', 'max:500'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            MaterialMemoStockService::correctReturn(
                $memoItem,
                (float) $request->qty_returned,
                $request->user('api')->id,
                $memo,
                (string) $request->note,
                $request->filled('expiry_date') ? (string) $request->expiry_date : null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $memo->load(['creator:id,name', 'store:id,name', 'items', 'booking:id,booking_number,customer_name']);

        return response()->json([
            'success' => true,
            'message' => 'Pengembalian dikoreksi.',
            'data' => $memo,
        ]);
    }

    public function updateItem(Request $request, int $id, int $itemId)
    {
        $memo = MaterialMemo::find($id);

        if (! $memo) {
            return response()->json(['success' => false, 'message' => 'Memo tidak ditemukan.'], 404);
        }

        if (! $this->authorizeMemoAccess($request, $memo)) {
            return $this->forbiddenResponse();
        }

        if ($denied = $this->denyUnlessCan($request, 'update')) {
            return $denied;
        }

        $memoItem = MaterialMemoItem::where('material_memo_id', $memo->id)->find($itemId);

        if (! $memoItem) {
            return response()->json(['success' => false, 'message' => 'Baris barang tidak ditemukan.'], 404);
        }

        if ($memoItem->item_type === 'offcut') {
            return response()->json(['success' => false, 'message' => 'Potongan sisa tidak punya jumlah yang bisa dikoreksi. Hapus barisnya kalau salah pilih.'], 422);
        }

        $isInventory = $memoItem->item_type === 'inventory_item';
        $validator = Validator::make($request->all(), [
            'qty_taken' => $isInventory ? 'nullable' : 'required|numeric|min:0.01|max:1000000',
            'meters_used' => $isInventory ? 'required|numeric|min:0.01|max:100000' : 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            if ($isInventory) {
                MaterialMemoStockService::updateInventoryQty($memoItem, (float) $request->meters_used, $memo);
            } else {
                MaterialMemoStockService::updateMaterialQty($memoItem, (float) $request->qty_taken, $request->user('api')->id, $memo);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $memo->load(['creator:id,name', 'store:id,name', 'items', 'booking:id,booking_number,customer_name']);

        return response()->json([
            'success' => true,
            'message' => 'Jumlah berhasil dikoreksi.',
            'data' => $memo,
        ]);
    }

    /**
     * DELETE /api/staff/memos/{id}/items/{itemId}
     *
     * Buat kasus salah pilih barang dari awal (bukan cuma salah angka —
     * itu pakai updateItem()). Stok/sisa meter yang sudah terpakai
     * dikembalikan penuh, baru barisnya dihapus.
     */
    public function destroyItem(Request $request, int $id, int $itemId)
    {
        $memo = MaterialMemo::find($id);

        if (! $memo) {
            return response()->json(['success' => false, 'message' => 'Memo tidak ditemukan.'], 404);
        }

        if (! $this->authorizeMemoAccess($request, $memo)) {
            return $this->forbiddenResponse();
        }

        if ($denied = $this->denyUnlessCan($request, 'update')) {
            return $denied;
        }

        $memoItem = MaterialMemoItem::where('material_memo_id', $memo->id)->find($itemId);

        if (! $memoItem) {
            return response()->json(['success' => false, 'message' => 'Baris barang tidak ditemukan.'], 404);
        }

        MaterialMemoStockService::reverseItem($memoItem, $request->user('api')->id, $memo);
        $memoItem->delete();

        $memo->load(['creator:id,name', 'store:id,name', 'items', 'booking:id,booking_number,customer_name']);

        return response()->json([
            'success' => true,
            'message' => 'Barang berhasil dihapus dari memo.',
            'data' => $memo,
        ]);
    }
}
