<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Carousel;
use Illuminate\Http\JsonResponse;

class CarouselController extends Controller
{
    public function index(): JsonResponse
    {
        $carousels = Carousel::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'title', 'subtitle', 'image', 'link_url']);

        return response()->json([
            'data' => $carousels->map(fn ($c) => [
                'id'       => $c->id,
                'title'    => $c->title,
                'subtitle' => $c->subtitle,
                'image'    => $c->image ? asset('storage/' . $c->image) : null,
                'link_url' => $c->link_url,
            ]),
        ]);
    }
}
