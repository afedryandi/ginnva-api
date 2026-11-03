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
    use \App\Http\Controllers\Api\Staff\Concerns\AdjustsStockInBulk;

    protected function stockModelClass(): string
    {
        return \App\Models\ConsumableItem::class;
    }

    protected function stockLabel(): string
    {
        return 'barang habis pakai';
    }

    private function authorizeAccess(Request $request): bool
    {
        // Eksplisit (bukan akses bawaan): akun dengan menu_access kosong tidak ikut (audit Barang Habis Pakai, 2026-11-02).
        return $request->user('api')?->hasConsumableAccess() ?? false;
    }

    /**
     * Daftar pencarian (tanpa harga) juga dipakai sebagai pemilih barang di Memo Bahan dan Permohonan Pembelian, jadi pemegang akses
     * kedua menu itu boleh memakainya. Detail dan pencatatan tetap butuh akses Barang Habis Pakai (authorizeAccess).
     */
    private function authorizeList(Request $request): bool
    {
        $user = $request->user('api');

        return $user !== null && ($user->hasConsumableAccess() || $user->hasMaterialMemoAccess() || $user->hasPurchaseRequestAccess());
    }

    /** Mencatat stok (masuk/keluar/opname) butuh izin "Ubah" pada menu Barang Habis Pakai, bukan sekadar bisa melihat. */
    private function canModify(Request $request): bool
    {
        return $this->authorizeAccess($request)
            && $request->user('api')->hasModuleAction(\App\Filament\Resources\ConsumableItemResource::class, 'update', false);
    }

    /** Harga modal hanya untuk akses penuh atau izin "Lihat" pada menu ini. */
    private function canSeeCost(Request $request): bool
    {
        $user = $request->user('api');

        return $user !== null && $this->authorizeAccess($request)
            && ($user->isFullAccess() || $user->hasModuleAction(\App\Filament\Resources\ConsumableItemResource::class, 'view', false));
    }

    private function denyUnlessWrite(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu Barang Habis Pakai.'], 403);
        }

        if (! $this->canModify($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini hanya boleh melihat stok barang habis pakai, tidak boleh mencatatnya.'], 403);
        }

        return null;
    }

    /** Hanya kolom yang dipakai app; harga ikut bila boleh dilihat. */
    private function shape(ConsumableItem $item, Request $request): ConsumableItem
    {
        $cost = $this->canSeeCost($request) ? ['unit_cost'] : [];

        $item->setVisible(array_merge(['id', 'name', 'code', 'category', 'unit', 'current_stock', 'reorder_point', 'adjustment_note_threshold', 'movements'], $cost));
        $this->shapeMovements($item->movements, $request);

        return $item;
    }

    private function shapeMovements(iterable $movements, Request $request): void
    {
        $cost = $this->canSeeCost($request) ? ['unit_cost'] : [];

        foreach ($movements as $movement) {
            $movement->setVisible(array_merge(['id', 'type', 'quantity', 'note', 'user', 'store', 'reversed_at', 'reversed_reason', 'created_at'], $cost));
        }
    }

    /**
     * GET /api/staff/consumables?search=...
     */
    public function index(Request $request)
    {
        if (! $this->authorizeList($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Barang Habis Pakai.',
            ], 403);
        }

        $search = trim((string) $request->query('search', ''));
        // filter=low: hanya yang stoknya di bawah ambang, yang paling kurang dulu (lihat RawMaterialController::index()).
        $filter = $request->query('filter') === 'low' ? 'low' : null;

        $query = ConsumableItem::query()->active()
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->when($filter === 'low', fn ($q) => $q->whereNotNull('reorder_point')->whereColumn('current_stock', '<=', 'reorder_point')
                ->orderByRaw('(current_stock - reorder_point) asc'));

        // Jumlah baris yang cocok sebelum dipotong batas, supaya app bisa bilang "menampilkan N dari M".
        $total = (clone $query)->reorder()->count();
        $limit = $filter ? 100 : 30;

        $items = $query
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'code', 'category', 'unit', 'current_stock', 'reorder_point'])
            ->each(fn (ConsumableItem $i) => $i->setAttribute('is_low_stock', $i->reorder_point !== null && (float) $i->current_stock <= (float) $i->reorder_point));

        return response()->json([
            'success' => true,
            'data' => $items,
            'total' => $total,
            'limit' => $limit,
            // Ringkasan seluruh barang aktif (tidak terpengaruh pencarian/filter) untuk chip di atas daftar.
            'summary' => [
                'all' => ConsumableItem::query()->active()->count(),
                'low' => ConsumableItem::query()->active()->whereNotNull('reorder_point')->whereColumn('current_stock', '<=', 'reorder_point')->count(),
            ],
            'can_modify' => $this->canModify($request),
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

        $item = ConsumableItem::with(['movements' => fn ($q) => $q->withoutGlobalScope('effective')->with(['user:id,name', 'store:id,name'])->limit(20)])->find($id);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Barang tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->shape($item, $request),
            'can_modify' => $this->canModify($request),
            'restock' => $this->restockInfo($item, $request),
            // Permohonan pembelian disetujui yang masih menunggu barang ini; app memintanya dicatat lewat Permohonan Pembelian.
            'pending_purchase' => $this->canModify($request) ? \App\Filament\Resources\ConsumableItemResource::approvedPurchase($item)?->only(['request_number', 'quantity', 'unit']) : null,
            // Alasan "Catat Terbuang" (sumber tunggal: StockWriteOff::REASON_LABELS); null bila akun tidak boleh mencatat.
            'write_off_reasons' => $this->canModify($request) ? \App\Models\StockWriteOff::REASON_LABELS : null,
            'card' => ['preferred_supplier' => $item->preferredSupplier?->name],
            // Dipakai app buat munculkan tombol "Muat Riwayat Lainnya" —
            // lihat movements() untuk endpoint paginasinya. Sama pola
            // dengan InventoryController::show().
            'movements_has_more' => $item->movements()->withoutGlobalScope('effective')->count() > $item->movements->count(),
        ]);
    }

    /**
     * GET /api/staff/consumable-movements?type=&search=&from=&until=&offset=
     *
     * Riwayat Barang Habis Pakai lintas barang (audit 2026-11-03): selama ini mobile hanya punya 20 baris terakhir per barang. Akses
     * penuh melihat semua toko, staf lain hanya baris bertanda tokonya sendiri. Harga hanya dengan izin Lihat.
     */
    public function history(Request $request)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu Barang Habis Pakai.'], 403);
        }

        $validator = Validator::make($request->query(), [
            'type' => 'nullable|in:in,out,adjustment,correction',
            'search' => 'nullable|string|max:100',
            'from' => 'nullable|date',
            'until' => 'nullable|date',
            'offset' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Filter tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user('api');
        $limit = 30;
        $offset = (int) $request->query('offset', 0);
        $search = trim((string) $request->query('search', ''));
        $cost = $this->canSeeCost($request);

        $query = \App\Models\ConsumableItemMovement::query()
            ->withoutGlobalScope('effective')
            ->visibleTo($user)
            ->with(['consumableItem:id,name,unit,category', 'user:id,name', 'store:id,name'])
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('from'), fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($request->query('until'), fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('note', 'like', "%{$search}%")
                ->orWhereHas('consumableItem', fn ($m) => $m->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))));

        $rows = (clone $query)->latest('created_at')->latest('id')->offset($offset)->limit($limit + 1)->get();

        $today = fn () => \App\Models\ConsumableItemMovement::query()->visibleTo($user)->whereDate('created_at', today());

        return response()->json([
            'success' => true,
            'data' => $rows->take($limit)->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'quantity' => (float) $m->quantity,
                'unit_cost' => $cost && $m->unit_cost !== null ? (float) $m->unit_cost : null,
                'note' => $m->note,
                'created_at' => $m->created_at?->toIso8601String(),
                'reversed' => $m->reversed_at !== null,
                'reversed_reason' => $m->reversed_reason,
                'source' => $m->sourceText(),
                'item' => $m->consumableItem ? ['id' => $m->consumableItem->id, 'name' => $m->consumableItem->name, 'unit' => $m->consumableItem->unit] : null,
                'user' => $m->user ? ['name' => $m->user->name] : null,
                'store' => $m->store ? ['name' => $m->store->name] : null,
            ])->values(),
            'has_more' => $rows->count() > $limit,
            'summary' => [
                'out_today' => $today()->where('type', 'out')->count(),
                'in_today' => $today()->where('type', 'in')->count(),
            ],
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
            ->withoutGlobalScope('effective')
            ->with(['user:id,name', 'store:id,name'])
            ->latest()
            ->offset($offset)
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => tap($movements, fn ($rows) => $this->shapeMovements($rows, $request)),
            'has_more' => $item->movements()->withoutGlobalScope('effective')->count() > $offset + $movements->count(),
        ]);
    }

    /**
     * POST /api/staff/consumables/{id}/movement
     */
    public function storeMovement(Request $request, int $id)
    {
        if ($denied = $this->denyUnlessWrite($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:in,out',
            'quantity' => 'required|numeric|min:0.01|max:1000000',
            'note' => 'nullable|string|max:500',
            // Opsional, cuma relevan untuk type=in — lihat
            // ConsumableItem::recordMovement(). Tidak ada UI-nya di
            // mobile (harga tetap tugas admin/Filament), API-nya
            // disiapkan supaya konsisten dengan RawMaterialController.
            'unit_cost' => 'nullable|numeric|min:0|max:999999999',
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
                // Harga hanya diterima dari akun yang boleh melihat harga modal.
                $request->filled('unit_cost') && $this->canSeeCost($request) ? (float) $request->unit_cost : null,
                // Penanda cabang pencatat, supaya kartu stok per cabang tidak kehilangan catatan dari HP.
                $request->user('api')->store_id,
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
            'data' => $this->shape($item->fresh()->load(['movements' => fn ($q) => $q->withoutGlobalScope('effective')->with(['user:id,name', 'store:id,name'])->limit(20)]), $request),
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
        if ($denied = $this->denyUnlessWrite($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'actual_quantity' => 'required|numeric|min:0|max:1000000',
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

        // Selisih di atas batas barang ini wajib punya catatan alasan (batas diatur per barang di panel; kosong = tidak ada aturan).
        if ($item->adjustmentNeedsNote((float) $request->actual_quantity) && trim((string) $request->note) === '') {
            $limit = rtrim(rtrim(number_format((float) $item->adjustment_note_threshold, 2, '.', ''), '0'), '.');

            return response()->json([
                'success' => false,
                'message' => "Selisih melebihi {$limit} {$item->unit} untuk barang ini. Isi catatan alasan selisih.",
                'errors' => ['note' => ["Catatan wajib diisi bila selisih lebih dari {$limit} {$item->unit}."]],
            ], 422);
        }

        $movement = $item->adjustStock((float) $request->actual_quantity, $request->user('api')->id, $request->note, $request->user('api')->store_id);

        return response()->json([
            'success' => true,
            'message' => $movement === null
                ? 'Hasil hitung fisik sama dengan stok di sistem — tidak ada penyesuaian yang dicatat.'
                : 'Stok disesuaikan (selisih ' . ($movement->quantity > 0 ? '+' : '') . $movement->quantity . ' ' . $item->unit . ').',
            'data' => $this->shape($item->fresh()->load(['movements' => fn ($q) => $q->withoutGlobalScope('effective')->with(['user:id,name', 'store:id,name'])->limit(20)]), $request),
        ]);
    }

    /** Status permintaan beli untuk detail barang; null bila akun tidak punya akses menu Permohonan Pembelian. */
    private function restockInfo(ConsumableItem $item, Request $request): ?array
    {
        $user = $request->user('api');

        if (! $this->authorizeAccess($request) || ! $user->hasPurchaseRequestAccess()) {
            return null;
        }

        $service = app(\App\Services\RestockRequestService::class);
        $open = $service->openRequest($item);

        return [
            'open_request' => $open ? ['request_number' => $open->request_number, 'status' => $open->status] : null,
            // Akun tanpa toko (akses penuh) mengajukan dari panel, tempat toko bisa dipilih.
            'can_request' => $item->isLowStock() && $open === null && $user->store_id !== null,
            'suggested_quantity' => $item->reorder_point !== null ? $service->suggestedQuantity($item) : null,
        ];
    }

    /**
     * POST /api/staff/consumables/{id}/write-off
     *
     * "Stok Terbuang" (rusak/kedaluwarsa/hilang/lainnya) -- jalur yang sama dengan tombol di panel (StockWriteOffService).
     */
    public function writeOff(Request $request, int $id)
    {
        if ($denied = $this->denyUnlessWrite($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'quantity' => 'required|numeric|min:0.01|max:1000000',
            'reason' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Models\StockWriteOff::REASON_LABELS))],
            // Catatan wajib untuk alasan Hilang dan Lainnya (audit Stok Terbuang 2026-11-03).
            'note' => [\Illuminate\Validation\Rule::requiredIf(fn () => in_array($request->input('reason'), \App\Models\StockWriteOff::REASONS_NEEDING_NOTE, true)), 'nullable', 'string', 'max:255'],
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
            return response()->json(['success' => false, 'message' => 'Barang tidak ditemukan.'], 404);
        }

        try {
            app(\App\Services\StockWriteOffService::class)->record(
                $item,
                'consumable_item',
                (float) $request->quantity,
                $request->reason,
                $request->filled('note') ? $request->note : null,
                $request->user('api')->id,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stok terbuang dicatat.',
            'data' => $this->shape($item->fresh()->load(['movements' => fn ($q) => $q->withoutGlobalScope('effective')->with(['user:id,name', 'store:id,name'])->limit(20)]), $request),
        ]);
    }

    /**
     * POST /api/staff/consumables/{id}/restock-request
     *
     * Ajukan permintaan beli untuk barang yang stoknya menipis (RestockRequestService): menunggu persetujuan, satu barang satu permintaan terbuka.
     */
    public function requestRestock(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu Barang Habis Pakai.'], 403);
        }

        $user = $request->user('api');

        if (! $user->hasPurchaseRequestAccess()) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu Permohonan Pembelian.'], 403);
        }

        if ($user->store_id === null) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak terikat toko. Ajukan permintaan beli dari panel admin.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'quantity' => 'required|numeric|min:0.01|max:1000000',
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
            return response()->json(['success' => false, 'message' => 'Barang tidak ditemukan.'], 404);
        }

        if (! $item->isLowStock()) {
            return response()->json(['success' => false, 'message' => 'Stok barang ini belum di bawah ambang menipis.'], 422);
        }

        try {
            $purchase = app(\App\Services\RestockRequestService::class)->create(
                $item,
                $user->store_id,
                (float) $request->quantity,
                $request->filled('note') ? $request->note : null,
                $user,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Permintaan beli {$purchase->request_number} dibuat. Menunggu persetujuan.",
            'data' => ['request_number' => $purchase->request_number, 'status' => $purchase->status],
        ]);
    }
}
