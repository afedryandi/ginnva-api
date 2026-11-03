<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Filament\Resources\RawMaterialResource;
use App\Models\RawMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RawMaterialController extends Controller
{
    use \App\Http\Controllers\Api\Staff\Concerns\AdjustsStockInBulk;

    protected function stockModelClass(): string
    {
        return \App\Models\RawMaterial::class;
    }

    protected function stockLabel(): string
    {
        return 'bahan baku';
    }

    /**
     * Sama pola dengan InventoryController::authorizeScan() — dicek lewat "Akses Menu" akun Filament staff (menu Bahan Baku),
     * dan eksplisit: akun dengan akses bawaan (menu_access kosong) tidak ikut (audit Daftar Bahan Baku, 2026-11-02).
     */
    private function authorizeAccess(Request $request): bool
    {
        return $request->user('api')?->hasMaterialAccess() ?? false;
    }

    /**
     * Daftar pencarian (tanpa harga) juga dipakai sebagai pemilih bahan di Memo Bahan dan Permohonan Pembelian, jadi pemegang
     * akses kedua menu itu boleh memakainya walau menu Daftar Bahan Baku tidak dicentang. Detail dan pencatatan tetap butuh
     * akses Daftar Bahan Baku (authorizeAccess).
     */
    private function authorizeList(Request $request): bool
    {
        $user = $request->user('api');

        return $user !== null && ($user->hasMaterialAccess() || $user->hasMaterialMemoAccess() || $user->hasPurchaseRequestAccess());
    }

    /** Mencatat stok (masuk/keluar/opname) butuh izin "Ubah" pada menu Daftar Bahan Baku, bukan sekadar bisa melihat. */
    private function canModify(Request $request): bool
    {
        return $this->authorizeAccess($request)
            && $request->user('api')->hasModuleAction(\App\Filament\Resources\RawMaterialResource::class, 'update', false);
    }

    /** Harga modal (dasar HPP) hanya untuk akses penuh atau izin "Lihat" pada menu ini. */
    private function canSeeCost(Request $request): bool
    {
        $user = $request->user('api');

        return $user !== null && $this->authorizeAccess($request)
            && ($user->isFullAccess() || $user->hasModuleAction(\App\Filament\Resources\RawMaterialResource::class, 'view', false));
    }

    private function denyUnlessWrite(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu Bahan Baku.'], 403);
        }

        if (! $this->canModify($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini hanya boleh melihat stok bahan baku, tidak boleh mencatatnya.'], 403);
        }

        return null;
    }

    /**
     * Status permintaan beli untuk detail bahan: permintaan yang sedang berjalan, apakah akun ini boleh mengajukan sekarang, dan
     * jumlah saran awal. Null bila akun tidak punya akses menu Permohonan Pembelian.
     */
    private function restockInfo(RawMaterial $material, Request $request): ?array
    {
        $user = $request->user('api');

        if (! $this->authorizeAccess($request) || ! $user->hasPurchaseRequestAccess()) {
            return null;
        }

        $service = app(\App\Services\RestockRequestService::class);
        $open = $service->openRequest($material);

        return [
            'open_request' => $open ? ['request_number' => $open->request_number, 'status' => $open->status] : null,
            // Akun tanpa toko (akses penuh) mengajukan dari panel, tempat toko bisa dipilih.
            'can_request' => $material->isLowStock() && $open === null && $user->store_id !== null,
            'suggested_quantity' => $material->reorder_point !== null ? $service->suggestedQuantity($material) : null,
        ];
    }

    /** Hanya kolom yang dipakai app; harga ikut bila boleh dilihat. Catatan internal, pembuat, dan penanda tinjauan tidak dikirim. */
    private function shape(RawMaterial $material, Request $request): RawMaterial
    {
        $cost = $this->canSeeCost($request) ? ['unit_cost'] : [];

        $material->setVisible(array_merge(['id', 'name', 'code', 'category', 'unit', 'purchase_unit', 'purchase_conversion_factor', 'current_stock', 'reorder_point', 'adjustment_note_threshold', 'movements', 'batches'], $cost));
        $this->shapeMovements($material->movements, $request);
        foreach ($material->batches as $batch) {
            $batch->setVisible(array_merge(['id', 'quantity', 'received_date', 'expiry_date', 'is_adjustment'], $cost));
        }

        return $material;
    }

    private function shapeMovements(iterable $movements, Request $request): void
    {
        $cost = $this->canSeeCost($request) ? ['unit_cost'] : [];

        foreach ($movements as $movement) {
            $movement->setVisible(array_merge(['id', 'type', 'quantity', 'note', 'user', 'store', 'reversed_at', 'reversed_reason', 'created_at'], $cost));
        }
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
        if (! $this->authorizeList($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Bahan Baku.',
            ], 403);
        }

        $search = trim((string) $request->query('search', ''));
        // filter=low (stok di bawah ambang) atau filter=expiry (ada batch kedaluwarsa/mendekati, 30 hari); tanpa filter, 30 baris
        // pertama menurut abjad saja, jadi bahan yang menipis bisa tak terlihat di daftar panjang.
        $filter = in_array($request->query('filter'), ['low', 'expiry'], true) ? $request->query('filter') : null;

        // Batch aktif (quantity>0) ikut di-eager-load supaya staff bisa
        // lihat status kedaluwarsa langsung dari daftar pencarian — dulu
        // index() ini sama sekali tidak mengirim info kedaluwarsa, staff
        // harus buka detail 1-1 untuk tahu. Dihitung dari koleksi yang
        // sudah dimuat (bukan panggil RawMaterial::earliestActiveExpiryDate()
        // per baris), supaya tidak N+1 query untuk 30 baris hasil.
        $query = RawMaterial::query()->active()
            ->select(['id', 'name', 'code', 'category', 'unit', 'current_stock', 'reorder_point'])
            ->with(['batches' => fn ($q) => $q->where('quantity', '>', 0)->whereNotNull('expiry_date')])
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->when($filter === 'low', fn ($q) => $q->whereNotNull('reorder_point')->whereColumn('current_stock', '<=', 'reorder_point')
                ->orderByRaw('(current_stock - reorder_point) asc'))
            ->when($filter === 'expiry', fn ($q) => $q->whereHas('batches', fn ($b) => $b->where('quantity', '>', 0)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', today()->addDays(30)->toDateString())));

        // Jumlah baris yang cocok sebelum dipotong batas, supaya app bisa bilang "menampilkan N dari M".
        $total = (clone $query)->reorder()->count();
        $limit = $filter ? 100 : 30;

        $materials = $query
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(function (RawMaterial $m) {
                $m->setAttribute('is_low_stock', $m->reorder_point !== null && (float) $m->current_stock <= (float) $m->reorder_point);
                $earliest = $m->batches->min('expiry_date');

                $m->setAttribute('nearest_expiry_date', $earliest);
                $m->setAttribute('is_expired', $earliest !== null && \Illuminate\Support\Carbon::parse($earliest)->isPast());
                $m->setAttribute('is_near_expiry', $earliest !== null && \Illuminate\Support\Carbon::parse($earliest)->lte(now()->addDays(30)));

                return $m->makeHidden('batches');
            });

        return response()->json([
            'success' => true,
            'data' => $materials,
            'total' => $total,
            'limit' => $limit,
            // Ringkasan seluruh bahan (tidak terpengaruh pencarian/filter) untuk chip di atas daftar.
            'summary' => [
                'all' => RawMaterial::query()->active()->count(),
                'low' => RawMaterial::query()->active()->whereNotNull('reorder_point')->whereColumn('current_stock', '<=', 'reorder_point')->count(),
                'expiry' => RawMaterial::query()->active()->whereHas('batches', fn ($b) => $b->where('quantity', '>', 0)
                    ->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '<=', today()->addDays(30)->toDateString()))->count(),
            ],
            'can_modify' => $this->canModify($request),
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
            'movements' => fn ($q) => $q->withoutGlobalScope('effective')->with(['user:id,name', 'store:id,name'])->limit(20),
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
            'data' => $this->shape($material, $request),
            // Dipakai app untuk menyembunyikan tombol catat/opname bagi akun yang hanya boleh melihat.
            'can_modify' => $this->canModify($request),
            'restock' => $this->restockInfo($material, $request),
            // Ringkasan untuk kartu atas detail: pemasok utama dan kedaluwarsa terdekat (dari batch yang masih ada stoknya).
            'card' => [
                'preferred_supplier' => $material->preferredSupplier?->name,
                'nearest_expiry_date' => $material->earliestActiveExpiryDate()?->toDateString(),
            ],
            // Permohonan pembelian disetujui yang masih menunggu barang ini; app memintanya dicatat lewat Permohonan Pembelian.
            'pending_purchase' => $this->canModify($request) ? RawMaterialResource::approvedPurchase($material)?->only(['request_number', 'quantity', 'unit']) : null,
            // Alasan "Catat Terbuang" (sumber tunggal: StockWriteOff::REASON_LABELS); null bila akun tidak boleh mencatat.
            'write_off_reasons' => $this->canModify($request) ? \App\Models\StockWriteOff::REASON_LABELS : null,
            // Dipakai app buat munculkan tombol "Muat Riwayat Lainnya" —
            // lihat movements() untuk endpoint paginasinya. SEBELUMNYA
            // modul ini satu-satunya dari 3 modul quantity-based/movement
            // (Inventory, Consumables, Materials) yang tidak punya
            // paginasi riwayat di mobile sama sekali.
            'movements_has_more' => $material->movements()->withoutGlobalScope('effective')->count() > $material->movements->count(),
        ]);
    }

    /**
     * GET /api/staff/material-movements?type=&search=&from=&until=&offset=
     *
     * Riwayat Bahan Baku lintas bahan (audit 2026-11-03): selama ini mobile hanya punya 20 baris terakhir per bahan. Akses penuh melihat
     * semua toko, staf lain hanya baris bertanda tokonya sendiri. Harga hanya dengan izin Lihat.
     */
    public function history(Request $request)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu Bahan Baku.'], 403);
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

        $query = \App\Models\RawMaterialMovement::query()
            ->withoutGlobalScope('effective')
            ->visibleTo($user)
            ->with(['rawMaterial:id,name,unit,category', 'user:id,name', 'store:id,name'])
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('from'), fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($request->query('until'), fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('note', 'like', "%{$search}%")
                ->orWhereHas('rawMaterial', fn ($m) => $m->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))));

        $rows = (clone $query)->latest('created_at')->latest('id')->offset($offset)->limit($limit + 1)->get();

        $today = fn () => \App\Models\RawMaterialMovement::query()->visibleTo($user)->whereDate('created_at', today());

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
                'material' => $m->rawMaterial ? ['id' => $m->rawMaterial->id, 'name' => $m->rawMaterial->name, 'unit' => $m->rawMaterial->unit] : null,
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
            ->withoutGlobalScope('effective')
            ->with(['user:id,name', 'store:id,name'])
            ->latest()
            ->offset($offset)
            ->limit($limit)
            ->get();

        $this->shapeMovements($movements, $request);

        return response()->json([
            'success' => true,
            'data' => $movements,
            'has_more' => $material->movements()->withoutGlobalScope('effective')->count() > $offset + $movements->count(),
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
        if ($denied = $this->denyUnlessWrite($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:in,out',
            'quantity' => 'required|numeric|min:0.01|max:1000000',
            'note' => 'nullable|string|max:500',
            // Cuma relevan untuk type=in — dipakai jadi tanggal batch baru
            // (lihat RawMaterial::recordMovement()). Boleh kosong (default
            // hari ini, tanpa kedaluwarsa) untuk staff yang catat cepat.
            'received_date' => 'nullable|date|before_or_equal:today',
            // Tidak boleh lebih awal dari tanggal masuk batch (bila diisi; kalau kosong, tanggal masuk = hari ini).
            'expiry_date' => ['nullable', 'date', \Illuminate\Validation\Rule::when($request->filled('received_date'), 'after_or_equal:received_date')],
            // Opsional, cuma relevan untuk type=in — harga beli batch ini
            // sendiri (untuk valuasi stok), lihat RawMaterial::recordMovement().
            'unit_cost' => 'nullable|numeric|min:0|max:999999999',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->has('expiry_date')
                    ? 'Tanggal kedaluwarsa tidak boleh lebih awal dari tanggal masuk.'
                    : 'Data yang dikirim tidak valid.',
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
                // Harga batch hanya diterima dari akun yang boleh melihat harga modal.
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

        // ->fresh() saja tidak ikut memuat relasi movements (lihat
        // perbaikan sama di InventoryController::storeMovement()) — app
        // saat ini fetch ulang setelah sukses jadi belum sampai crash,
        // tapi disamakan di sini juga supaya konsisten & aman kalau nanti
        // app dipakai langsung dari respons ini.
        $material = $material->fresh();
        $material->load([
            'movements' => fn ($q) => $q->withoutGlobalScope('effective')->with(['user:id,name', 'store:id,name'])->limit(20),
            'batches' => fn ($q) => $q->where('quantity', '>', 0),
        ]);

        return response()->json([
            'success' => true,
            'message' => $request->type === 'in' ? 'Bahan masuk berhasil dicatat.' : 'Bahan keluar berhasil dicatat.',
            'data' => $this->shape($material, $request),
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

        $material = RawMaterial::find($id);

        if (! $material) {
            return response()->json([
                'success' => false,
                'message' => 'Bahan baku tidak ditemukan.',
            ], 404);
        }

        // Selisih di atas batas bahan ini wajib punya catatan alasan (batas diatur per bahan di panel; kosong = tidak ada aturan).
        if ($material->adjustmentNeedsNote((float) $request->actual_quantity) && trim((string) $request->note) === '') {
            $limit = rtrim(rtrim(number_format((float) $material->adjustment_note_threshold, 2, '.', ''), '0'), '.');

            return response()->json([
                'success' => false,
                'message' => "Selisih melebihi {$limit} {$material->unit} untuk bahan ini. Isi catatan alasan selisih.",
                'errors' => ['note' => ["Catatan wajib diisi bila selisih lebih dari {$limit} {$material->unit}."]],
            ], 422);
        }

        $movement = $material->adjustStock((float) $request->actual_quantity, $request->user('api')->id, $request->note, $request->user('api')->store_id);

        $material = $material->fresh();
        $material->load([
            'movements' => fn ($q) => $q->withoutGlobalScope('effective')->with(['user:id,name', 'store:id,name'])->limit(20),
            'batches' => fn ($q) => $q->where('quantity', '>', 0),
        ]);

        return response()->json([
            'success' => true,
            'message' => $movement === null
                ? 'Hasil hitung fisik sama dengan stok di sistem — tidak ada penyesuaian yang dicatat.'
                : 'Stok disesuaikan (selisih ' . ($movement->quantity > 0 ? '+' : '') . $movement->quantity . ' ' . $material->unit . ').',
            'data' => $this->shape($material, $request),
        ]);
    }

    /**
     * POST /api/staff/materials/{id}/restock-request
     *
     * Ajukan permintaan beli untuk bahan yang stoknya menipis, tanpa pindah ke layar Permohonan Pembelian dan mencari bahannya lagi.
     * Jalurnya sama dengan tombol di panel (RestockRequestService): menunggu persetujuan, satu bahan satu permintaan terbuka.
     */
    public function requestRestock(Request $request, int $id)
    {
        if (! $this->authorizeAccess($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu Bahan Baku.'], 403);
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

        $material = RawMaterial::find($id);

        if (! $material) {
            return response()->json(['success' => false, 'message' => 'Bahan baku tidak ditemukan.'], 404);
        }

        if (! $material->isLowStock()) {
            return response()->json(['success' => false, 'message' => 'Stok bahan ini belum di bawah ambang menipis.'], 422);
        }

        try {
            $purchase = app(\App\Services\RestockRequestService::class)->create(
                $material,
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

    /**
     * POST /api/staff/materials/{id}/write-off
     *
     * "Stok Terbuang" (rusak/kedaluwarsa/hilang/lainnya) -- jalur yang sama dengan tombol di panel (StockWriteOffService):
     * stok berkurang, tercatat di laporan Stok Terbuang, dan jurnal kerugian dibuat bila modul Keuangan menyala. Sebelumnya
     * staf di lapangan hanya bisa memakai "Keluar" biasa, yang tidak meninggalkan jejak kerugian.
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

        $material = RawMaterial::find($id);

        if (! $material) {
            return response()->json([
                'success' => false,
                'message' => 'Bahan baku tidak ditemukan.',
            ], 404);
        }

        try {
            app(\App\Services\StockWriteOffService::class)->record(
                $material,
                'raw_material',
                (float) $request->quantity,
                $request->reason,
                $request->filled('note') ? $request->note : null,
                $request->user('api')->id,
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $material = $material->fresh();
        $material->load([
            'movements' => fn ($q) => $q->withoutGlobalScope('effective')->with(['user:id,name', 'store:id,name'])->limit(20),
            'batches' => fn ($q) => $q->where('quantity', '>', 0),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Stok terbuang dicatat.',
            'data' => $this->shape($material, $request),
        ]);
    }
}
