<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Carousel;
use Illuminate\Http\JsonResponse;

class CarouselController extends Controller
{
    public function index(): JsonResponse
    {
        $carousels = Carousel::live()
            ->whereIn('audience', ['customer', 'both'])
            ->orderBy('sort_order')
            ->get(['id', 'title', 'subtitle', 'image', 'alt_text', 'link_url']);

        return response()->json([
            'success' => true,
            'data' => $this->transform($carousels),
        ]);
    }

    /**
     * GET /api/partner/promos
     * Requires: auth:api (partner)
     */
    public function partnerPromos(): JsonResponse
    {
        $carousels = Carousel::live()
            ->whereIn('audience', ['partner', 'both'])
            ->orderBy('sort_order')
            ->get(['id', 'title', 'subtitle', 'image', 'alt_text', 'link_url']);

        return response()->json([
            'success' => true,
            'data' => $this->transform($carousels),
        ]);
    }

    /**
     * POST /api/carousels/{id}/click -- menghitung ketukan banner (Marketing/Konten Fase 5). Hanya banner yang sedang tayang yang dihitung;
     * angka kasar (tanpa identitas pengguna), cukup untuk membandingkan banner.
     */
    public function click(int $id): JsonResponse
    {
        $counted = Carousel::live()->whereKey($id)->increment('click_count');

        return response()->json(['success' => true, 'counted' => $counted > 0]);
    }

    private function transform($carousels)
    {
        return $carousels->map(fn ($c) => [
            'id'       => $c->id,
            'title'    => $c->title,
            'subtitle' => $c->subtitle,
            'image'    => $c->image ? asset('storage/' . $c->image) : null,
            'alt_text' => $c->alt_text,
            'link_url' => $c->link_url,
        ]);
    }
}
