<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AssetController extends Controller
{
    private const LIST_LIMIT = 20;

    /**
     * Dicek lewat "Akses Menu" akun Filament staff (menu Aset Tetap), dan eksplisit: akun dengan akses bawaan (menu_access kosong)
     * tidak ikut (audit Aset Tetap, 2026-11-02).
     */
    private function authorizeScan(Request $request): bool
    {
        return $request->user('api')?->hasAssetAccess() ?? false;
    }

    /** Mengubah status/catatan dan serah terima butuh izin "Ubah" pada menu Aset Tetap, bukan sekadar bisa melihat. */
    private function canModify(Request $request): bool
    {
        return $this->authorizeScan($request)
            && $request->user('api')->hasModuleAction(\App\Filament\Resources\AssetResource::class, 'update', false);
    }

    /** Harga beli (dan tanggal beli) hanya untuk akses penuh atau izin "Lihat" pada menu ini. */
    private function canSeeCost(Request $request): bool
    {
        $user = $request->user('api');

        return $user !== null && $this->authorizeScan($request)
            && ($user->isFullAccess() || $user->hasModuleAction(\App\Filament\Resources\AssetResource::class, 'view', false));
    }

    private function deniedMenu(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu Aset.'], 403);
    }

    private function denyUnlessWrite(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! $this->authorizeScan($request)) {
            return $this->deniedMenu();
        }

        if (! $this->canModify($request)) {
            return response()->json(['success' => false, 'message' => 'Akun ini hanya boleh melihat aset, tidak boleh mengubahnya.'], 403);
        }

        return null;
    }

    private function notFound(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Aset dengan kode ini tidak ditemukan.'], 404);
    }

    /** Hanya kolom yang dipakai app. Harga ikut bila boleh dilihat; nilai lama/baru di log aktivitas (yang memuat harga) tidak dikirim. */
    private function shape(Asset $asset, Request $request): Asset
    {
        $cost = $this->canSeeCost($request) ? ['purchase_date', 'purchase_cost'] : [];

        $asset->setVisible(array_merge(
            ['id', 'asset_tag', 'name', 'category', 'status', 'assignee', 'store', 'notes', 'next_maintenance_date', 'maintenance_interval_days', 'last_maintenance_date', 'disposed_at', 'activities'],
            $cost,
        ));

        foreach ($asset->activities as $activity) {
            $activity->setVisible(['id', 'event', 'description', 'causer', 'created_at']);
        }

        return $asset;
    }

    private function loadForResponse(Asset $asset): Asset
    {
        return $asset->load([
            'assignee:id,name',
            'store:id,name',
            'activities' => fn ($q) => $q->with('causer:id,name')->latest()->limit(20),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function recentTransfers(Asset $asset): array
    {
        return $asset->transfers()
            ->with(['fromUser:id,name', 'toUser:id,name', 'fromStore:id,name', 'toStore:id,name', 'performedBy:id,name'])
            ->limit(10)
            ->get()
            ->map(fn (AssetTransfer $t) => [
                'id' => $t->id,
                'from_user' => $t->fromUser?->name,
                'to_user' => $t->toUser?->name,
                'from_store' => $t->fromStore?->name,
                'to_store' => $t->toStore?->name,
                'condition' => $t->condition_at_transfer,
                'reason' => $t->reason,
                'performed_by' => $t->performedBy?->name,
                'created_at' => $t->created_at,
            ])
            ->all();
    }

    /**
     * GET /api/staff/assets?search=...&assigned_to_me=1
     */
    public function index(Request $request)
    {
        if (! $this->authorizeScan($request)) {
            return $this->deniedMenu();
        }

        $user = $request->user('api');
        $search = trim((string) $request->query('search', ''));

        $query = Asset::query()
            ->visibleTo($user)
            ->when($request->boolean('assigned_to_me'), fn ($q) => $q->where('assigned_to', $user->id))
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")
                ->orWhere('asset_tag', 'like', "%{$search}%")));

        // Jumlah yang cocok sebelum dipotong batas, supaya app bisa bilang "menampilkan N dari M".
        $total = (clone $query)->count();

        $assets = $query
            ->orderBy('name')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'asset_tag', 'name', 'category', 'status']);

        return response()->json([
            'success' => true,
            'data' => $assets,
            'total' => $total,
            'limit' => self::LIST_LIMIT,
            'can_modify' => $this->canModify($request),
        ]);
    }

    /**
     * GET /api/staff/assets/{code}
     */
    public function show(Request $request, string $code)
    {
        if (! $this->authorizeScan($request)) {
            return $this->deniedMenu();
        }

        $user = $request->user('api');
        $asset = Asset::where('asset_tag', $code)->first();

        if (! $asset || ! Asset::isVisibleTo($user, $asset)) {
            return $this->notFound();
        }

        $this->loadForResponse($asset);
        $canModify = $this->canModify($request);

        // Calon pemegang untuk Serah Terima: staf satu toko dengan akun ini (akses penuh: semua staf).
        $holders = $canModify
            ? User::query()
                ->when(! $user->isFullAccess(), fn ($q) => $q->where('store_id', $user->store_id))
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->filter(fn (User $u) => $u->canAccessStaffArea())
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
                ->values()
            : [];

        return response()->json([
            'success' => true,
            'data' => $this->shape($asset, $request),
            'can_modify' => $canModify,
            'transfers' => $this->recentTransfers($asset),
            'holders' => $holders,
            // Daftar toko hanya berguna untuk akses penuh yang memindahkan aset lewat Serah Terima.
            'stores' => $user->isFullAccess() ? Store::orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    /**
     * POST /api/staff/assets/{code}/update
     *
     * Status dan catatan. Perpindahan lokasi dan pergantian pemegang lewat Serah Terima (transfer()), supaya selalu ada catatan
     * siapa menyerahkan ke siapa, kondisi fisik, dan alasannya.
     */
    public function update(Request $request, string $code)
    {
        if ($denied = $this->denyUnlessWrite($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:aktif,diperbaiki,rusak,dijual,hilang',
            'store_id' => 'nullable|exists:stores,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $asset = Asset::where('asset_tag', $code)->first();
        $user = $request->user('api');

        if (! $asset || ! Asset::isVisibleTo($user, $asset)) {
            return $this->notFound();
        }

        if ($user->isFullAccess() && $request->filled('store_id') && (int) $request->store_id !== (int) $asset->store_id) {
            return response()->json([
                'success' => false,
                'message' => 'Memindahkan lokasi aset sekarang lewat Serah Terima, supaya tercatat siapa yang menyerahkan dan kondisinya.',
            ], 422);
        }

        // Aset yang sudah dilepas (Lepas Aset) tidak diubah dari app; "Dijual" hanya lewat Lepas Aset, "Hilang" boleh tanpa jurnal.
        if ($asset->disposed_at !== null) {
            return response()->json(['success' => false, 'message' => 'Aset ini sudah dilepas (dijual atau dibuang), jadi tidak bisa diubah dari aplikasi.'], 422);
        }

        if ($request->status === 'dijual' && $asset->status !== 'dijual') {
            return response()->json(['success' => false, 'message' => 'Status Dijual hanya lewat Lepas Aset di panel (akses penuh), supaya jurnal penjualannya tercatat.', 'errors' => ['status' => ['Status Dijual hanya lewat Lepas Aset di panel.']]], 422);
        }

        DB::transaction(function () use ($asset, $request) {
            $locked = Asset::where('id', $asset->id)->lockForUpdate()->firstOrFail();

            $locked->update([
                'status' => $request->status,
                'notes' => $request->has('notes') ? $request->notes : $locked->notes,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Aset berhasil diperbarui.',
            'data' => $this->shape($this->loadForResponse($asset->fresh()), $request),
        ]);
    }

    /**
     * POST /api/staff/assets/{code}/transfer
     *
     * Serah Terima -- jalur yang sama dengan tombol di panel: catatan AssetTransfer (dari/ke pemegang dan toko, kondisi fisik,
     * alasan, pelaku) lalu pemegang dan lokasi aset diperbarui dalam satu transaksi. Pindah toko hanya untuk akses penuh.
     */
    public function transfer(Request $request, string $code)
    {
        if ($denied = $this->denyUnlessWrite($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'to_user_id' => 'nullable|exists:users,id',
            'to_store_id' => 'nullable|exists:stores,id',
            'condition_at_transfer' => 'required|in:baik,perlu_perhatian,rusak',
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user('api');
        $asset = Asset::where('asset_tag', $code)->first();

        if (! $asset || ! Asset::isVisibleTo($user, $asset)) {
            return $this->notFound();
        }

        if ($asset->disposed_at !== null) {
            return response()->json(['success' => false, 'message' => 'Aset ini sudah dilepas (dijual atau dibuang), jadi tidak bisa diserahterimakan.'], 422);
        }

        if ($request->filled('to_user_id') && ! User::find($request->to_user_id)?->canAccessStaffArea()) {
            return response()->json([
                'success' => false,
                'message' => 'Pemegang harus staff yang punya akses ke panel.',
                'errors' => ['to_user_id' => ['Pemegang harus staff yang punya akses ke panel.']],
            ], 422);
        }

        DB::transaction(function () use ($asset, $user, $request) {
            $locked = Asset::where('id', $asset->id)->lockForUpdate()->firstOrFail();

            $toStoreId = ($user->isFullAccess() && $request->filled('to_store_id')) ? (int) $request->to_store_id : $locked->store_id;

            AssetTransfer::create([
                'asset_id' => $locked->id,
                'from_user_id' => $locked->assigned_to,
                'to_user_id' => $request->filled('to_user_id') ? (int) $request->to_user_id : null,
                'from_store_id' => $locked->store_id,
                'to_store_id' => $toStoreId,
                'condition_at_transfer' => $request->condition_at_transfer,
                'reason' => $request->reason,
                'performed_by' => $user->id,
            ]);

            $locked->update([
                'assigned_to' => $request->filled('to_user_id') ? (int) $request->to_user_id : null,
                'store_id' => $toStoreId,
            ]);
        });

        $fresh = $this->loadForResponse($asset->fresh());

        return response()->json([
            'success' => true,
            'message' => 'Serah terima dicatat.',
            'data' => $this->shape($fresh, $request),
            'transfers' => $this->recentTransfers($fresh),
        ]);
    }

    /**
     * POST /api/staff/assets/{code}/maintenance
     *
     * Catat Servis -- jalur yang sama dengan tombol di panel (Asset::recordMaintenance()). Jadwal berikutnya: bila `next_date` dikirim
     * (boleh kosong) dipakai apa adanya; bila tidak dikirim, dihitung dari interval servis aset ini (kalau ada), selain itu dikosongkan.
     */
    public function maintenance(Request $request, string $code)
    {
        if ($denied = $this->denyUnlessWrite($request)) {
            return $denied;
        }

        $validator = Validator::make($request->all(), [
            'date' => 'required|date|before_or_equal:today',
            'next_date' => 'nullable|date|after_or_equal:date',
            'note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $asset = Asset::where('asset_tag', $code)->first();

        if (! $asset || ! Asset::isVisibleTo($request->user('api'), $asset)) {
            return $this->notFound();
        }

        if ($asset->disposed_at !== null) {
            return response()->json(['success' => false, 'message' => 'Aset ini sudah dilepas (dijual atau dibuang), jadi servisnya tidak dicatat lagi.'], 422);
        }

        $date = \Illuminate\Support\Carbon::parse($request->date);
        $next = $request->has('next_date')
            ? ($request->filled('next_date') ? \Illuminate\Support\Carbon::parse($request->next_date) : null)
            : $asset->nextMaintenanceFrom($date);

        $asset->recordMaintenance($date, $next, $request->filled('note') ? $request->note : null, $request->user('api')->id);

        return response()->json([
            'success' => true,
            'message' => 'Servis dicatat.',
            'data' => $this->shape($this->loadForResponse($asset->fresh()), $request),
        ]);
    }
}
