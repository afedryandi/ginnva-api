<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
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

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(\App\Filament\Resources\InventoryItemResource::class);
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
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhereHas('scrollCode', fn ($sq) => $sq->where('code', 'like', "%{$search}%")))
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'code', 'name', 'category', 'status', 'scroll_code_id']);

        return response()->json([
            'success' => true,
            'data' => $items,
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
                'movements' => fn ($q) => $q->with('user:id,name')->limit(20),
                'scrollCode.filmProduct',
                'scrollCode.store:id,name',
                'scrollCode.usages' => fn ($q) => $q->with('user:id,name')->limit(20),
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
     *
     * Toko tujuan (untuk sinkron Kode Gulungan) diambil OTOMATIS dari
     * store_id akun staff yang scan — tidak perlu pilih manual. Cuma
     * full-access (super_admin/direksi, tidak terikat 1 toko) yang wajib
     * kirim store_id manual saat scan KELUAR barang yang punya kode
     * gulungan; kalau tidak dikirim, request ditolak (bukan dibiarkan
     * kosong diam-diam) supaya datanya tetap lengkap.
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
            'store_id' => 'nullable|exists:stores,id',
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

        $user = $request->user('api');
        $storeId = $user->isFullAccess() ? $request->store_id : $user->store_id;

        if (
            $request->type === 'out'
            && $item->scroll_code_id
            && $user->isFullAccess()
            && ! $request->filled('store_id')
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Pilih toko tujuan dulu — barang ini punya kode gulungan yang perlu dialokasikan.',
            ], 422);
        }

        try {
            $item->recordMovement($request->type, $user->id, $request->note, $storeId);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        // ->fresh() SAJA tidak ikut memuat relasi movements (beda dari
        // show() yang eksplisit ->with('movements')) — app mobile
        // menimpa state layar pakai respons ini, jadi kalau movements
        // tidak ikut dikirim, item.movements.length di app crash.
        $item = $item->fresh();
        $item->load([
            'movements' => fn ($q) => $q->with('user:id,name')->limit(20),
            'scrollCode.filmProduct',
            'scrollCode.store:id,name',
            'scrollCode.usages' => fn ($q) => $q->with('user:id,name')->limit(20),
        ]);

        return response()->json([
            'success' => true,
            'message' => $request->type === 'in' ? 'Barang masuk berhasil dicatat.' : 'Barang keluar berhasil dicatat.',
            'data' => $item,
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
        if (! $this->authorizeScan($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Inventaris.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'meters' => 'required|numeric|min:0.01',
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

        if (! $item || ! $item->scroll_code_id) {
            return response()->json([
                'success' => false,
                'message' => 'Barang ini tidak punya kode gulungan terkait.',
            ], 404);
        }

        $scrollCode = ScrollCode::find($item->scroll_code_id);

        try {
            $scrollCode->recordUsage((float) $request->meters, $request->user('api')->id, $request->note);
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
            'movements' => fn ($q) => $q->with('user:id,name')->limit(20),
            'scrollCode.filmProduct',
            'scrollCode.store:id,name',
            'scrollCode.usages' => fn ($q) => $q->with('user:id,name')->limit(20),
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
        if (! $this->authorizeScan($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Akun ini tidak punya akses ke menu Inventaris.',
            ], 403);
        }

        $item = InventoryItem::where('code', $code)->first();

        if (! $item || ! $item->scroll_code_id) {
            return response()->json([
                'success' => false,
                'message' => 'Barang ini tidak punya kode gulungan terkait.',
            ], 404);
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
            'movements' => fn ($q) => $q->with('user:id,name')->limit(20),
            'scrollCode.filmProduct',
            'scrollCode.store:id,name',
            'scrollCode.usages' => fn ($q) => $q->with('user:id,name')->limit(20),
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
}
