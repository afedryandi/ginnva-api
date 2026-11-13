<?php

namespace App\Http\Controllers\Api\Staff;

use App\Filament\Resources\ConsumableItemResource;
use App\Filament\Resources\RawMaterialResource;
use App\Http\Controllers\Controller;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\OpnameNeedsNoteException;
use App\Services\StockOpnameService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Stok Opname dari mobile (audit Stok Opname 2026-11-03): layar Hitung mengirim hasil hitung fisik sebagai sesi DRAF (stok belum berubah;
 * akses penuh menerapkannya dari panel). Hitung buta: stok sistem baru dikirim balik setelah hasil hitung terkirim. Boleh mengirim bagi akun
 * yang berizin Ubah pada menu bahan/barangnya (sama dengan "Hitung" sebelumnya); daftar sesi dibatasi toko.
 */
class StockOpnameController extends Controller
{
    private const LIMIT = 30;

    private function canRead(User $user): bool
    {
        return $user->hasMaterialAccess() || $user->hasConsumableAccess();
    }

    private function canWrite(User $user, string $type): bool
    {
        return $type === 'raw_material'
            ? $user->hasMaterialAccess() && $user->hasModuleAction(RawMaterialResource::class, 'update', false)
            : $user->hasConsumableAccess() && $user->hasModuleAction(ConsumableItemResource::class, 'update', false);
    }

    private function shape(StockOpname $o, bool $withItems): array
    {
        $o->loadMissing('store:id,name', 'creator:id,name');

        return [
            'id' => $o->id,
            'number' => $o->opname_number,
            'status' => $o->status,
            'status_label' => StockOpname::STATUS_LABELS[$o->status] ?? $o->status,
            'date' => $o->opname_date?->toDateString(),
            'store' => $o->store?->name,
            'by' => $o->creator?->name,
            'notes' => $o->notes,
            'created_at' => $o->created_at?->toIso8601String(),
            'items_count' => $o->items_count ?? $o->items()->count(),
            'diff_count' => $o->diff_count ?? $o->items()->where('delta', '!=', 0)->count(),
            'cancel_reason' => $o->cancel_reason,
            'items' => $withItems ? $o->items->map(fn ($i) => [
                'item_type' => $i->item_type,
                'item_id' => $i->item_id,
                'name' => $i->item_name,
                'unit' => $i->unit,
                'system' => (float) $i->system_quantity,
                'actual' => (float) $i->actual_quantity,
                'delta' => (float) $i->delta,
                'note' => $i->note,
            ])->values() : null,
        ];
    }

    private function scoped(User $user)
    {
        return StockOpname::query()
            ->withCount(['items', 'items as diff_count' => fn ($q) => $q->where('delta', '!=', 0)])
            ->when(! $user->isFullAccess(), fn ($q) => $q->where('store_id', $user->store_id));
    }

    public function index(Request $request)
    {
        $user = $request->user('api');

        if (! $this->canRead($user)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu ' . (config('features.consumables') ? 'Bahan Baku maupun Barang Habis Pakai' : 'Bahan Baku') . '.'], 403);
        }

        $status = in_array($request->query('status'), array_keys(StockOpname::STATUS_LABELS), true) ? $request->query('status') : null;
        $offset = max(0, (int) $request->query('offset', 0));

        $rows = $this->scoped($user)->with('store:id,name', 'creator:id,name')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('created_at')->latest('id')->offset($offset)->limit(self::LIMIT + 1)->get();

        return response()->json([
            'success' => true,
            'data' => $rows->take(self::LIMIT)->map(fn ($o) => $this->shape($o, false))->values(),
            'has_more' => $rows->count() > self::LIMIT,
        ]);
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user('api');

        if (! $this->canRead($user)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu ' . (config('features.consumables') ? 'Bahan Baku maupun Barang Habis Pakai' : 'Bahan Baku') . '.'], 403);
        }

        $opname = $this->scoped($user)->with('items')->find($id);

        if (! $opname) {
            return response()->json(['success' => false, 'message' => 'Sesi tidak ditemukan.'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->shape($opname, true)]);
    }

    public function store(Request $request)
    {
        $user = $request->user('api');

        $validator = Validator::make($request->all(), [
            'store_id' => 'nullable|integer|exists:stores,id',
            'opname_date' => 'nullable|date',
            'notes' => 'nullable|string|max:255',
            'items' => 'required|array|min:1|max:300',
            'items.*.item_type' => 'required|in:raw_material,consumable_item',
            'items.*.item_id' => 'required|integer',
            'items.*.actual_quantity' => 'required|numeric|min:0|max:1000000',
            'items.*.note' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Data yang dikirim tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $items = $request->input('items');

        foreach (array_unique(array_column($items, 'item_type')) as $type) {
            if (! $this->canWrite($user, $type)) {
                return response()->json(['success' => false, 'message' => 'Akun ini tidak punya izin Ubah pada menu ' . ($type === 'raw_material' ? 'Bahan Baku' : 'Barang Habis Pakai') . '.'], 403);
            }
        }

        $storeId = $user->isFullAccess() ? (int) $request->input('store_id') : (int) $user->store_id;

        if ($storeId <= 0) {
            return response()->json([
                'success' => false,
                'message' => $user->isFullAccess() ? 'Pilih toko untuk sesi ini.' : 'Akun ini belum terikat ke toko, sesi tidak bisa dibuat.',
            ], 422);
        }

        try {
            $opname = app(StockOpnameService::class)->submit($storeId, $request->input('opname_date', today()->toDateString()), $request->input('notes'), $items, $user->id);
        } catch (OpnameNeedsNoteException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'problems' => $e->problems,
                // Format yang dibaca ApiError di mobile: kunci row_{id} per baris yang bermasalah.
                'errors' => collect($e->problems)->mapWithKeys(fn (array $p) => ['row_' . $p['item_id'] => [$p['message']]])->all(),
            ], 422);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Hasil hitung terkirim sebagai {$opname->opname_number}. Stok berubah setelah diterapkan oleh admin.",
            'data' => $this->shape($opname->loadCount(['items', 'items as diff_count' => fn ($q) => $q->where('delta', '!=', 0)]), true),
        ], 201);
    }
}