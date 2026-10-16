<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/product-claims[?series=A70] -- klaim produk yang SUDAH DISETUJUI (Marketing/Konten Fase 2), dikelompokkan per seri. Web dan
 * aplikasi membaca angka dari sini; klaim draft atau yang ditarik tidak pernah keluar.
 */
class ProductClaimController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductClaim::approved()->orderBy('series')->orderBy('id');

        if ($request->filled('series')) {
            $query->where('series', (string) $request->query('series'));
        }

        $grouped = $query->get(['series', 'claim_type', 'label', 'value', 'tested_at'])
            ->groupBy('series')
            ->map(fn ($claims) => $claims->map(fn (ProductClaim $c) => [
                'type' => $c->claim_type,
                'label' => $c->label,
                'value' => $c->value,
                'tested_at' => $c->tested_at?->toDateString(),
            ])->values());

        return response()->json(['success' => true, 'data' => $grouped])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
