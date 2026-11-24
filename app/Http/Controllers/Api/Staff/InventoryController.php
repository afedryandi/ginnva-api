<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\InventoryItem;
use App\Models\ScrollCode;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // Eksplisit (bukan akses bawaan akun baru): lihat InventoryItemResource::canViewAny().
        return $user?->hasExplicitMenuAccess(\App\Filament\Resources\InventoryItemResource::class) ?? false;
    }

    /**
     * Mengubah data (catat masuk/keluar, pemakaian meter, tandai habis) butuh izin Ubah eksplisit selain akses menu; pemegang menu
     * saja hanya boleh membaca. Mengembalikan respons penolakan, atau null bila boleh.
     */
    private function denyUnlessWrite(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! $this->authorizeScan($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu Inventaris.'], 403);
        }

        if (! ($request->user('api')->hasModuleAction(\App\Filament\Resources\InventoryItemResource::class, 'update', false))) {
            return response()->json(['success' => false, 'message' => 'Akun ini hanya boleh melihat data Produk PPF/WF, tidak boleh mengubahnya.'], 403);
        }

        return null;
    }

    /**
     * Barang (kardus fisik) sendiri company-wide, tapi begitu kode
     * gulungan terkaitnya SUDAH dialokasi/dipakai oleh 1 toko
     * (scroll_code.store_id terisi), staff toko LAIN tidak boleh
     * mencatat pemakaian/menandai habis kode itu lewat scan mobile —
     * dulu tidak ada pengecekan ini sama sekali (cuma cek akses menu),
     * sama pola dengan InventoryItemResource::canActOnScrollCode().
     */
    private function canActOnScrollCode(?ScrollCode $scrollCode, $user): bool
    {
        if (! $scrollCode || $scrollCode->store_id === null) {
            return true;
        }

        return ($user?->isFullAccess() ?? false) || $scrollCode->store_id === $user?->store_id;
    }

    /**
     * GET /api/staff/inventory?search=...
     *
     * Alternatif untuk staff yang belum bisa scan QR langsung di tempat
     * (mis. mencatat dari jarak jauh) — cari lewat nama/kode barang ATAU
     * kode gulungan terkait (mis. staff cuma pegang catatan kode
     * gulungan, bukan kode kardus), lalu pilih dari hasilnya.
     * Company-wide (tidak di-scope per toko), sama seperti
     * InventoryItemResource di Filament.
     */
    public function index(Request $request)
    {
        if (! $this->authorizeScan($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Inventaris.',
            ], 403);
        }

        $search = trim((string) $request->query('search', ''));

        $items = InventoryItem::query()
            ->with('scrollCode:id,code,remaining_length_meters')
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhereHas('scrollCode', fn ($sq) => $sq->where('code', 'like', "%{$search}%"))))
            // status=in_stock (di gudang) atau status=out (sudah keluar), supaya daftar 30 baris pertama tidak menyembunyikan yang dicari.
            ->when(in_array($request->query('status'), ['in_stock', 'out'], true), fn ($q) => $q->where('status', $request->query('status')))
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'code', 'name', 'category', 'status', 'scroll_code_id']);

        // SEBELUMNYA staff yang cari kode gulungan yang SUDAH terdaftar
        // tapi BELUM pernah dikaitkan ke barang fisik apa pun akan selalu
        // dapat hasil kosong tanpa penjelasan sama sekali ("Tidak ada
        // barang ditemukan.") -- padahal query di atas cuma mencari di
        // tabel InventoryItem, jadi kode yang belum punya barang terkait
        // memang mustahil ketemu lewat jalur ini. Staff tidak tahu apakah
        // kodenya salah ketik atau memang belum dikaitkan. Cek terpisah
        // di sini (cuma kalau hasil kosong, supaya tidak nge-query ekstra
        // percuma tiap kali) untuk kasih hint yang benar. Ditemukan lewat
        // testing manual 2026-09-01.
        $hint = null;
        if ($items->isEmpty() && $search !== '') {
            $orphanScrollCode = ScrollCode::where('code', $search)
                ->whereDoesntHave('inventoryItem')
                ->first();

            if ($orphanScrollCode) {
                $hint = "Kode gulungan \"{$orphanScrollCode->code}\" belum dikaitkan ke barang. Kaitkan dulu lewat menu Produk PPF/WF di panel sebelum pemakaiannya dicatat.";
            }
        }

        return response()->json([
            'success' => true,
            'data' => $items,
            'hint' => $hint,
        ]);
    }

    /**
     * GET /api/staff/inventory/{code}
     *
     * Dipanggil begitu staff selesai scan QR di app mobile — cari barang
     * berdasarkan kode unik yang ter-encode di QR, sekalian kembalikan
     * riwayat keluar-masuk terakhir (20 baris) supaya staff bisa lihat
     * konteks sebelum mencatat transaksi baru. Kode gulungan terkait
     * (kalau ada) ikut disertakan supaya kelihatan di detail barang.
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
            ->with([
                'movements' => fn ($q) => $q->with(['user:id,name', 'destinationStore:id,name', 'store:id,name'])->limit(20),
                'scrollCode.filmProduct',
                'scrollCode.store:id,name',
                'scrollCode.usages' => fn ($q) => $q->with(['user:id,name', 'spk:id,spk_number'])->limit(20),
            ])
            ->first();

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Barang dengan kode ini tidak ditemukan.',
            ], 404);
        }

        $user = $request->user('api');

        return response()->json([
            'success' => true,
            'data' => $item,
            // Cuma dipakai app kalau user full-access DAN barangnya punya
            // kode gulungan — untuk pilih toko tujuan manual saat scan
            // keluar. Staff biasa tidak butuh ini (toko-nya otomatis dari
            // akun sendiri, lihat storeMovement()).
            'stores' => $user->isFullAccess() ? Store::orderBy('name')->get(['id', 'name']) : [],
            // Dipakai app buat munculkan tombol "Muat Riwayat Lainnya" —
            // lihat movements() untuk endpoint paginasinya.
            'movements_has_more' => $item->movements()->count() > $item->movements->count(),
        ]);
    }

    /**
     * GET /api/staff/inventory-movements?type=&search=&from=&until=&store_id=&offset=
     *
     * Riwayat Keluar/Masuk lintas barang (audit 2026-11-03): selama ini mobile hanya punya 20 baris terakhir per barang setelah scan.
     * Akses penuh melihat semua toko (boleh menyaring store_id); staf lain hanya baris tokonya sendiri (toko pencatat atau tujuan).
     */
    public function history(Request $request)
    {
        if (! $this->authorizeScan($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu Inventaris.'], 403);
        }

        $validator = Validator::make($request->query(), [
            'type' => 'nullable|in:in,out,correction',
            'search' => 'nullable|string|max:100',
            'from' => 'nullable|date',
            'until' => 'nullable|date',
            'store_id' => 'nullable|integer',
            'offset' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Filter tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user('api');
        $limit = 30;
        $offset = (int) $request->query('offset', 0);
        $search = trim((string) $request->query('search', ''));

        $query = \App\Models\InventoryMovement::query()
            ->visibleTo($user)
            ->with(['inventoryItem:id,code,name,category', 'user:id,name', 'destinationStore:id,name', 'store:id,name'])
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('from'), fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($request->query('until'), fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->when($user->isFullAccess() && $request->filled('store_id'), fn ($q) => $q->where(fn ($w) => $w
                ->where('inventory_movements.store_id', (int) $request->query('store_id'))
                ->orWhere('inventory_movements.destination_store_id', (int) $request->query('store_id'))))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('note', 'like', "%{$search}%")
                ->orWhereHas('inventoryItem', fn ($i) => $i->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))));

        $rows = (clone $query)->latest('created_at')->latest('id')->offset($offset)->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;

        $today = fn () => \App\Models\InventoryMovement::query()->visibleTo($user)->effective()->whereDate('created_at', today());

        // Ringkasan mengikuti PERIODE yang dipilih (from/until), bukan jenis atau pencarian: dua angka itu dibaca berdampingan, jadi memilih
        // "Keluar" tidak boleh membuat angka Masuk jadi 0. Tanpa from/until = seluruh riwayat yang boleh dilihat akun ini.
        $period = fn () => \App\Models\InventoryMovement::query()->visibleTo($user)->effective()
            ->when($request->query('from'), fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($request->query('until'), fn ($q, $date) => $q->whereDate('created_at', '<=', $date));

        return response()->json([
            'success' => true,
            'data' => $rows->take($limit)->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'note' => $m->note,
                'created_at' => $m->created_at?->toIso8601String(),
                'reversed' => $m->reversed_at !== null,
                'reversed_reason' => $m->reversed_reason,
                'item' => $m->inventoryItem ? ['code' => $m->inventoryItem->code, 'name' => $m->inventoryItem->name, 'category' => $m->inventoryItem->category] : null,
                'user' => $m->user ? ['name' => $m->user->name] : null,
                'destination_store' => $m->destinationStore ? ['name' => $m->destinationStore->name] : null,
                'store' => $m->store ? ['name' => $m->store->name] : null,
            ])->values(),
            'has_more' => $hasMore,
            'summary' => [
                'out_today' => $today()->where('type', 'out')->count(),
                'in_today' => $today()->where('type', 'in')->count(),
            ],
            'period_summary' => [
                'out' => $period()->where('type', 'out')->count(),
                'in' => $period()->where('type', 'in')->count(),
            ],
        ]);
    }

    /**
     * GET /api/staff/inventory/{code}/movements?offset=20
     *
     * "Muat Riwayat Lainnya" — show()/storeMovement() cuma kirim 20 baris
     * pertama supaya respons awal ringan; endpoint ini yang dipanggil
     * mobile kalau staff mau lihat riwayat lebih lama dari itu.
     * SEBELUMNYA tidak ada jalan sama sekali untuk melihat riwayat di
     * luar 20 baris terakhir dari app — cuma bisa lewat Filament.
     */
    public function movements(Request $request, string $code)
    {
        if (! $this->authorizeScan($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Inventaris.',
            ], 403);
        }

        $item = InventoryItem::where('code', $code)->first(['id']);

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Barang dengan kode ini tidak ditemukan.',
            ], 404);
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $limit = 20;

        $movements = $item->movements()
            ->with(['user:id,name', 'destinationStore:id,name', 'store:id,name'])
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
     * DITUTUP (2026-11-23): pencatatan masuk/keluar produk PPF/WF dan alokasi gulungan hanya lewat panel admin (Produk PPF/WF), bukan
     * aplikasi. Rutenya sengaja dipertahankan supaya aplikasi lama yang belum diperbarui menerima pesan yang jelas, bukan error 404 generik.
     * Logika pergerakannya tetap ada di InventoryItem::recordMovement() dan dipakai panel.
     */
    public function storeMovement(Request $request, string $code)
    {
        return response()->json([
            'success' => false,
            'message' => 'Pencatatan masuk dan keluar produk PPF/WF sekarang hanya lewat panel admin.',
        ], 403);
    }

    /**
     * GET /api/staff/inventory/{code}/spks?search=...
     *
     * Pilihan SPK untuk "Catat Pemakaian": SPK toko akun ini (akses penuh: toko gulungan barang ini), yang masih dikerjakan lebih dulu.
     */
    public function spks(Request $request, string $code)
    {
        if ($denied = $this->denyUnlessWrite($request)) {
            return $denied;
        }

        $item = InventoryItem::where('code', $code)->first();
        $scrollCode = $item?->scroll_code_id ? ScrollCode::find($item->scroll_code_id) : null;
        $user = $request->user('api');
        $storeId = $user->isFullAccess() ? $scrollCode?->store_id : $user->store_id;

        if (! $storeId) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $search = trim((string) $request->query('search', ''));

        $spks = \App\Models\Spk::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('spk_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('vehicle_plate', 'like', "%{$search}%")))
            ->orderByRaw('checked_out_at IS NULL DESC')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'spk_number', 'customer_name', 'vehicle_brand', 'vehicle_plate', 'booking_id', 'checked_out_at']);

        return response()->json(['success' => true, 'data' => $spks]);
    }

    /**
     * GET /api/staff/inventory/{code}/offcuts
     *
     * Potongan sisa (wasted) yang masih tersimpan dari gulungan barang ini, untuk dipakai langsung ke SPK (tanpa memo).
     */
    public function offcuts(Request $request, string $code)
    {
        if ($denied = $this->denyUnlessWrite($request)) {
            return $denied;
        }

        $item = InventoryItem::where('code', $code)->first();
        $scrollCode = $item?->scroll_code_id ? ScrollCode::find($item->scroll_code_id) : null;

        if (! $scrollCode) {
            return response()->json(['success' => true, 'data' => []]);
        }

        if (! $this->canActOnScrollCode($scrollCode, $request->user('api'))) {
            return response()->json(['success' => false, 'message' => 'Kode gulungan ini teralokasi ke toko lain.'], 403);
        }

        $offcuts = $scrollCode->offcuts()->stored()->orderBy('id')->get(['id', 'location', 'notes', 'created_at']);

        return response()->json(['success' => true, 'data' => $offcuts]);
    }

    /**
     * POST /api/staff/inventory/{code}/offcuts/{id}/use
     *
     * Pakai satu potongan sisa untuk sebuah SPK: spk_id (pilih) atau spk_number (ketik) serta car_part (bagian mobil) wajib.
     */
    public function useOffcut(Request $request, string $code, int $id)
    {
        if ($denied = $this->denyUnlessWrite($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'spk_id' => 'nullable|integer|exists:spks,id',
            'spk_number' => 'nullable|string|max:100',
            'car_part' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Data yang dikirim tidak valid.', 'errors' => $validator->errors()], 422);
        }

        if (config('features.usage_spk_required') && ! $request->filled('spk_id') && ! filled(trim((string) $request->spk_number))) {
            return response()->json([
                'success' => false,
                'message' => 'Isi nomor SPK atau pilih dari daftar.',
                'errors' => ['spk_number' => ['Isi nomor SPK atau pilih dari daftar.']],
            ], 422);
        }

        $item = InventoryItem::where('code', $code)->first();
        $scrollCode = $item?->scroll_code_id ? ScrollCode::find($item->scroll_code_id) : null;
        $user = $request->user('api');

        if (! $scrollCode) {
            return response()->json(['success' => false, 'message' => 'Barang ini tidak punya kode gulungan terkait.'], 404);
        }

        if (! $this->canActOnScrollCode($scrollCode, $user)) {
            return response()->json(['success' => false, 'message' => 'Kode gulungan ini teralokasi ke toko lain.'], 403);
        }

        $offcut = $scrollCode->offcuts()->where('id', $id)->first();

        if (! $offcut) {
            return response()->json(['success' => false, 'message' => 'Potongan tidak ditemukan.'], 404);
        }

        if ($request->filled('spk_id')) {
            $spk = \App\Models\Spk::withoutGlobalScopes()->find($request->spk_id);
            $expectedStore = $user->isFullAccess() ? $scrollCode->store_id : $user->store_id;

            if ($spk && $expectedStore !== null && (int) $spk->store_id !== (int) $expectedStore) {
                return response()->json(['success' => false, 'message' => 'SPK ini milik toko lain.', 'errors' => ['spk_id' => ['SPK ini milik toko lain.']]], 422);
            }
        }

        try {
            $offcut->markUsed(
                $request->filled('spk_id') ? (int) $request->spk_id : null,
                filled(trim((string) $request->spk_number)) ? trim((string) $request->spk_number) : null,
                (string) $request->car_part,
                $user->id,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Potongan sisa dipakai.',
            'data' => $scrollCode->offcuts()->stored()->orderBy('id')->get(['id', 'location', 'notes', 'created_at']),
        ]);
    }

    /**
     * POST /api/staff/inventory/{code}/record-usage
     *
     * Catat berapa meter dipakai dari kode gulungan terkait barang ini —
     * dipakai staff yang sedang pasang PPF/WF ke mobil. Cuma bisa kalau
     * kode gulungannya sudah punya Total Panjang (diisi admin lewat
     * Filament, lihat ScrollCode::recordUsage()).
     */
    public function recordUsage(Request $request, string $code)
    {
        if ($denied = $this->denyUnlessWrite($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'meters' => 'required|numeric|min:0.01|max:100000',
            'note' => 'nullable|string|max:500',
            'booking_id' => 'nullable|integer|exists:bookings,id',
            // SPK terkait: pilih dari daftar (spk_id) atau ketik nomornya (spk_number). Wajib begitu aplikasi barunya beredar (saklar fitur).
            'spk_id' => 'nullable|integer|exists:spks,id',
            'spk_number' => 'nullable|string|max:100',
            // Potongan sisa (wasted) yang disimpan setelah pemakaian ini (opsional); gantinya "Simpan Potongan Sisa" dari baris roll di memo.
            'offcut_count' => 'nullable|integer|min:0|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (config('features.usage_spk_required') && ! $request->filled('spk_id') && ! filled(trim((string) $request->spk_number))) {
            return response()->json([
                'success' => false,
                'message' => 'Isi nomor SPK atau pilih dari daftar.',
                'errors' => ['spk_number' => ['Isi nomor SPK atau pilih dari daftar.']],
            ], 422);
        }

        $item = InventoryItem::where('code', $code)->first();

        if (! $item || ! $item->scroll_code_id) {
            return response()->json([
                'success' => false,
                'message' => 'Barang ini tidak punya kode gulungan terkait.',
            ], 404);
        }

        $scrollCode = ScrollCode::find($item->scroll_code_id);
        $user = $request->user('api');

        if (! $this->canActOnScrollCode($scrollCode, $user)) {
            return response()->json([
                'success' => false,
                'message' => 'Kode gulungan ini teralokasi ke toko lain.',
            ], 403);
        }

        // Traceability roll -> booking (diminta user 2026-09-15): cuma
        // boleh dilink ke booking yang sudah 'confirmed' (bukan pending,
        // supaya tidak nyambung ke booking yang bisa saja ditolak), dan
        // harus 1 toko yang sama kecuali full-access.
        if ($request->filled('booking_id')) {
            $booking = Booking::find($request->booking_id);

            if (! $booking || $booking->status !== 'confirmed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking yang dipilih tidak valid atau belum dikonfirmasi.',
                ], 422);
            }

            if (! $user->isFullAccess() && $booking->store_id !== $user->store_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking ini milik toko lain.',
                ], 403);
            }
        }

        // SPK yang dipilih harus milik toko yang sama (staf biasa: tokonya sendiri; akses penuh: toko gulungan ini).
        if ($request->filled('spk_id')) {
            $spk = \App\Models\Spk::withoutGlobalScopes()->find($request->spk_id);
            $expectedStore = $user->isFullAccess() ? $scrollCode->store_id : $user->store_id;

            if ($spk && $expectedStore !== null && (int) $spk->store_id !== (int) $expectedStore) {
                return response()->json([
                    'success' => false,
                    'message' => 'SPK ini milik toko lain.',
                    'errors' => ['spk_id' => ['SPK ini milik toko lain.']],
                ], 422);
            }
        }

        try {
            $scrollCode->recordUsage(
                (float) $request->meters,
                $user->id,
                $request->note,
                $request->booking_id,
                $request->filled('spk_id') ? (int) $request->spk_id : null,
                filled(trim((string) $request->spk_number)) ? trim((string) $request->spk_number) : null,
            );

            if ((int) $request->offcut_count > 0) {
                $scrollCode->storeOffcuts(
                    (int) $request->offcut_count,
                    null,
                    'Disimpan saat Catat Pemakaian' . (filled($request->spk_number) ? ' (SPK ' . trim((string) $request->spk_number) . ')' : ''),
                    $user->id,
                );
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // Sama seperti storeMovement()/markScrollCodeUsed() — movements
        // WAJIB ikut di-reload di sini juga, kalau tidak, app mobile
        // crash karena item.movements.length dipanggil atas undefined.
        $item->load([
            'movements' => fn ($q) => $q->with(['user:id,name', 'destinationStore:id,name', 'store:id,name'])->limit(20),
            'scrollCode.filmProduct',
            'scrollCode.store:id,name',
            'scrollCode.usages' => fn ($q) => $q->with(['user:id,name', 'spk:id,spk_number'])->limit(20),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pemakaian berhasil dicatat.',
            'data' => $item,
        ]);
    }

    /**
     * POST /api/staff/inventory/{code}/mark-scroll-code-used
     *
     * Sama tujuannya dengan "Tandai Habis" di menu Kode Gulungan
     * (ScrollCodeResource) — ditambahkan juga di sini supaya staff yang
     * pegang barang fisiknya langsung bisa tandai habis tanpa pindah
     * menu. Cuma untuk kode yang statusnya 'allocated' (sudah keluar,
     * belum ditandai habis).
     */
    public function markScrollCodeUsed(Request $request, string $code)
    {
        if ($denied = $this->denyUnlessWrite($request)) {
            return $denied;
        }

        $item = InventoryItem::where('code', $code)->first();

        if (! $item || ! $item->scroll_code_id) {
            return response()->json([
                'success' => false,
                'message' => 'Barang ini tidak punya kode gulungan terkait.',
            ], 404);
        }

        if (! $this->canActOnScrollCode($item->scrollCode, $request->user('api'))) {
            return response()->json([
                'success' => false,
                'message' => 'Kode gulungan ini teralokasi ke toko lain.',
            ], 403);
        }

        $wasMarked = DB::transaction(function () use ($item) {
            $scrollCode = ScrollCode::where('id', $item->scroll_code_id)->lockForUpdate()->first();

            if ($scrollCode && $scrollCode->status === 'allocated') {
                $scrollCode->update(['status' => 'used', 'used_at' => now()]);

                return true;
            }

            return false;
        });

        $item->load([
            'movements' => fn ($q) => $q->with(['user:id,name', 'destinationStore:id,name', 'store:id,name'])->limit(20),
            'scrollCode.filmProduct',
            'scrollCode.store:id,name',
            'scrollCode.usages' => fn ($q) => $q->with(['user:id,name', 'spk:id,spk_number'])->limit(20),
        ]);

        if (! $wasMarked) {
            return response()->json([
                'success' => false,
                'message' => 'Kode gulungan ini sudah berubah status (mungkin baru saja ditandai oleh orang lain) — coba muat ulang.',
                'data' => $item,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Kode gulungan ditandai habis.',
            'data' => $item,
        ]);
    }

    // collectScrap() / fitur "Sisa Roll" (RollScrapPool) DIHAPUS TOTAL
    // 2026-09-15 (diminta user, dibatalkan -- gap arsitektur
    // traceability roll_number vs Garansi belum terselesaikan, tim
    // memutuskan tidak jadi dilanjutkan). Endpoint POST
    // /api/staff/inventory/{code}/collect-scrap ikut dihapus dari
    // routes/api.php.
}