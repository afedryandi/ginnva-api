<?php

namespace App\Http\Controllers\Api\Staff;

use App\Filament\Resources\StockWriteOffResource;
use App\Http\Controllers\Controller;
use App\Models\StockWriteOff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * GET /api/staff/stock-write-offs?reason=&jenis=&from=&until=&offset= — daftar Stok Terbuang di mobile (audit Stok Terbuang 2026-11-03).
 * Dibuka bagi akun yang punya akses Bahan Baku atau Barang Habis Pakai; jenis yang tampil mengikuti menu asalnya. Akses penuh melihat semua
 * toko, staf lain hanya tokonya (StoreScope + filter eksplisit). Nilai rupiah hanya bila akunnya akses penuh atau berizin Lihat pada menu
 * Stok Terbuang. Yang dibatalkan tampil dengan penanda.
 */
class StockWriteOffController extends Controller
{
    private const LIMIT = 30;

    public function index(Request $request)
    {
        $user = $request->user('api');
        $canMaterial = $user->hasMaterialAccess();
        $canConsumable = $user->hasConsumableAccess();

        if (! $canMaterial && ! $canConsumable) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu Bahan Baku maupun Barang Habis Pakai.'], 403);
        }

        $validator = Validator::make($request->query(), [
            'reason' => 'nullable|in:' . implode(',', array_keys(StockWriteOff::REASON_LABELS)),
            'jenis' => 'nullable|in:raw_material,consumable_item',
            'from' => 'nullable|date',
            'until' => 'nullable|date',
            'offset' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Filter tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $kinds = array_values(array_filter([$canMaterial ? 'raw_material' : null, $canConsumable ? 'consumable_item' : null]));
        $jenis = $request->query('jenis');

        if ($jenis !== null && ! in_array($jenis, $kinds, true)) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke jenis barang itu.'], 403);
        }

        $showValue = $user->isFullAccess() || $user->hasModuleAction(StockWriteOffResource::class, 'view', false);
        $offset = (int) $request->query('offset', 0);

        $query = StockWriteOff::query()
            ->withoutGlobalScope('effective')
            ->with(['creator:id,name', 'store:id,name'])
            ->whereIn('writeoffable_type', $jenis ? [$jenis] : $kinds)
            ->when(! $user->isFullAccess(), fn ($q) => $q->where('store_id', $user->store_id))
            ->when($request->query('reason'), fn ($q, $reason) => $q->where('reason', $reason))
            ->when($request->query('from'), fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($request->query('until'), fn ($q, $date) => $q->whereDate('created_at', '<=', $date));

        $rows = (clone $query)->latest('created_at')->latest('id')->offset($offset)->limit(self::LIMIT + 1)->get();

        $month = (clone $query)->whereNull('cancelled_at')->where('created_at', '>=', now()->startOfMonth());

        return response()->json([
            'success' => true,
            'data' => $rows->take(self::LIMIT)->map(fn (StockWriteOff $w) => [
                'id' => $w->id,
                'number' => $w->write_off_number,
                'created_at' => $w->created_at?->toIso8601String(),
                'item_name' => $w->item_name,
                'item_id' => $w->writeoffable_id,
                'jenis' => $w->writeoffable_type,
                'quantity' => (float) $w->quantity,
                'unit' => $w->unit,
                'reason' => $w->reason,
                'reason_label' => StockWriteOff::REASON_LABELS[$w->reason] ?? $w->reason,
                'note' => $w->note,
                'value' => $showValue && $w->total_value !== null ? (float) $w->total_value : null,
                'by' => $w->creator?->name,
                'store' => $w->store?->name,
                'cancelled' => $w->cancelled_at !== null,
                'cancelled_reason' => $w->cancelled_reason,
            ])->values(),
            'has_more' => $rows->count() > self::LIMIT,
            'summary' => [
                'month_count' => (clone $month)->count(),
                'month_value' => $showValue ? (float) (clone $month)->sum('total_value') : null,
            ],
            'show_value' => $showValue,
        ]);
    }
}