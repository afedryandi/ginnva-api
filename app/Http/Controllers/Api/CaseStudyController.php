<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaseStudy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class CaseStudyController extends Controller
{
    /**
     * GET /api/case-studies
     *
     * Daftar galeri pemasangan untuk home page (menggantikan CASE_DATA
     * hardcoded di CaseAndNewsSection.tsx). Hanya is_active = true,
     * diurutkan sesuai sort_order yang diatur admin lewat drag-reorder
     * di Filament.
     */
    public function index(): JsonResponse
    {
        $items = CaseStudy::query()
            ->where('is_active', true)
            ->with(['vehicle', 'filmProduct'])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (CaseStudy $item) => $this->transform($item));

        return response()->json([
            'success' => true,
            'data'    => $items,
        ]);
    }

    /**
     * image disimpan sebagai path relatif oleh Filament FileUpload.
     * Sama seperti NewsController::fullImageUrl() — dipaksa jadi full
     * absolute URL secara manual (gabung dengan APP_URL), supaya tidak
     * terulang masalah gambar broken di frontend (domain berbeda) yang
     * sebelumnya terjadi pada cover_image berita.
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
                'id'   => $item->filmProduct->id,
                'name' => $item->filmProduct->name,
            ] : null,
            'sort_order'   => $item->sort_order,
        ];
    }

    private function fullImageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $relative = Storage::url($path);

        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }

        return rtrim(config('app.url'), '/') . '/' . ltrim($relative, '/');
    }
}