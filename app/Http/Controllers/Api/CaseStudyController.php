<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesPublicFileUrl;
use App\Http\Controllers\Controller;
use App\Models\CaseStudy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaseStudyController extends Controller
{
    use ResolvesPublicFileUrl;

    /**
     * GET /api/case-studies
     *
     * Daftar galeri pemasangan. Parameter opsional:
     *   ?product_type=window_film  → hanya item yang film_product-nya bertipe window_film
     *   ?product_type=ppf          → hanya PPF
     *   (tanpa parameter)          → semua (dipakai home page & galeri umum)
     *
     * Nilai product_type mengacu pada enum di tabel film_products
     * (window_film | ppf | color_change).
     */
    public function index(Request $request): JsonResponse
    {
        $query = CaseStudy::query()
            ->where('is_active', true)
            ->with(['vehicle', 'filmProduct'])
            ->orderBy('sort_order');

        // Filter per-produk — dipakai halaman product detail web
        if ($request->filled('product_type')) {
            $query->whereHas('filmProduct', function ($q) use ($request) {
                $q->where('product_type', $request->input('product_type'));
            });
        }

        $items = $query->get()->map(fn (CaseStudy $item) => $this->transform($item));

        return response()->json([
            'success' => true,
            'data'    => $items,
        ]);
    }

    /**
     * image disimpan sebagai path relatif oleh Filament FileUpload.
     * Dipaksa jadi full absolute URL supaya frontend lintas-domain bisa
     * load gambar tanpa perlu prefix manual.
     */
    private function transform(CaseStudy $item): array
    {
        return [
            'id'           => $item->id,
            'title'        => $item->title,
            'short_title'  => $item->short_title,
            'image'        => $this->fullImageUrl($item->image),
            'vehicle'      => $item->vehicle ? [
                'id'    => $item->vehicle->id,
                'brand' => $item->vehicle->brand,
                'model' => $item->vehicle->model,
            ] : null,
            'film_product' => $item->filmProduct ? [
                'id'           => $item->filmProduct->id,
                'name'         => $item->filmProduct->name,
                'product_type' => $item->filmProduct->product_type,
            ] : null,
            'sort_order'   => $item->sort_order,
        ];
    }
}