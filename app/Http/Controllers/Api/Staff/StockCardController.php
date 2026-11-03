<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Services\StockCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * GET /api/staff/stock-card?from=&until=&jenis=&search=&only_moved=&offset= — "Rekap Stok" di mobile (audit Daftar Stok 2026-11-03):
 * Awal, Masuk, Keluar, Akhir per barang untuk satu rentang, dihitung StockCardService yang sama dengan panel. Tanpa harga. Stok bersifat
 * nasional. Jenis yang boleh dilihat mengikuti menu asalnya: akun hanya-bahan-baku tidak melihat barang habis pakai, dan sebaliknya.
 */
class StockCardController extends Controller
{
    private const LIMIT = 50;

    public function index(Request $request)
    {
        $user = $request->user('api');
        $canMaterial = $user->hasMaterialAccess();
        $canConsumable = $user->hasConsumableAccess();

        if (! $canMaterial && ! $canConsumable) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke menu Bahan Baku maupun Barang Habis Pakai.'], 403);
        }

        $validator = Validator::make($request->query(), [
            'from' => 'nullable|date',
            'until' => 'nullable|date',
            'jenis' => 'nullable|in:all,raw_material,consumable_item',
            'search' => 'nullable|string|max:100',
            'only_moved' => 'nullable|boolean',
            'offset' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Filter tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $allowed = $canMaterial && $canConsumable ? 'all' : ($canMaterial ? 'raw_material' : 'consumable_item');
        $jenis = $request->query('jenis', 'all');

        if ($jenis === 'all') {
            $jenis = $allowed;
        } elseif ($allowed !== 'all' && $jenis !== $allowed) {
            return response()->json(['success' => false, 'message' => 'Akun ini tidak punya akses ke jenis barang itu.'], 403);
        }

        $from = Carbon::parse($request->query('from', now()->startOfMonth()))->startOfDay();
        $to = Carbon::parse($request->query('until', now()->endOfMonth()))->endOfDay();

        if ($to->lt($from)) {
            $to = $from->copy()->endOfDay();
        }

        $result = app(StockCardService::class)->build($from, $to, $jenis, $request->query('search'), null, $request->boolean('only_moved'));
        $offset = (int) $request->query('offset', 0);

        return response()->json([
            'success' => true,
            'data' => $result['rows']->slice($offset, self::LIMIT)->map(fn (array $row) => [
                'id' => $row['id'],
                'source' => $row['source'],
                'name' => $row['name'],
                'code' => $row['code'],
                'category' => $row['category'],
                'jenis' => $row['jenis'],
                'unit' => $row['unit'],
                'awal' => $row['awal'],
                'masuk' => $row['masuk'],
                'keluar' => $row['keluar'],
                'akhir' => $row['akhir'],
                'low' => $row['low'],
                'inactive' => $row['inactive'],
                'anomaly' => $row['anomaly'],
                'has_movement' => $row['hasMovement'],
            ])->values(),
            'has_more' => $result['rows']->count() > $offset + self::LIMIT,
            'totals' => $result['totals'],
            'from' => $from->toDateString(),
            'until' => $to->toDateString(),
            'available_jenis' => $allowed,
        ]);
    }
}