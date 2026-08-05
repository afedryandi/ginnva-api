<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeaturedProduct;
use Illuminate\Http\JsonResponse;

class FeaturedProductController extends Controller
{
    public function index(): JsonResponse
    {
        // Top 4 sesuai kurasi manual admin (urutan lewat sort_order) —
        // section "Seri Produk" di beranda mobile app.
        $products = FeaturedProduct::where('is_active', true)
            ->orderBy('sort_order')
            ->limit(4)
            ->get(['id', 'title', 'subtitle', 'image', 'content_image', 'link_url']);

        return response()->json(['success' => true, 'data' => $this->mapProducts($products)]);
    }

    /**
     * GET /api/featured-products/all
     * Halaman "Lihat Semua" — SEMUA kartu aktif (tidak dibatasi 4 seperti
     * di beranda), dipakai layar app/seri-produk/index.tsx.
     */
    public function all(): JsonResponse
    {
        $products = FeaturedProduct::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'title', 'subtitle', 'image', 'content_image', 'link_url']);

        return response()->json(['success' => true, 'data' => $this->mapProducts($products)]);
    }

    private function mapProducts($products)
    {
        return $products->map(fn ($p) => [
            'id'            => $p->id,
            'title'         => $p->title,
            'subtitle'      => $p->subtitle,
            // Thumbnail beranda/list — dipotong cover di sisi mobile.
            'image'         => $p->image ? asset('storage/' . $p->image) : null,
            // Gambar utuh saat kartu di-tap — fallback ke thumbnail kalau
            // admin belum upload gambar isi terpisah.
            'content_image' => $p->content_image
                ? asset('storage/' . $p->content_image)
                : ($p->image ? asset('storage/' . $p->image) : null),
            'link_url'      => $p->link_url,
        ]);
    }
}
