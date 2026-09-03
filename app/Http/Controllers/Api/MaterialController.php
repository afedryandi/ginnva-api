<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesPublicFileUrl;
use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialCategory;
use Illuminate\Http\JsonResponse;

class MaterialController extends Controller
{
    use ResolvesPublicFileUrl;

    public function index(): JsonResponse
    {
        $categories = MaterialCategory::with([
            'materials' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
        ])
            ->orderBy('sort_order')
            ->get()
            // Kategori tanpa materi aktif (belum diisi, atau semua isinya
            // dinonaktifkan admin) TIDAK ikut dikirim — sebelumnya tetap
            // muncul sebagai section kosong tanpa isi di sisi client.
            ->filter(fn (MaterialCategory $cat) => $cat->materials->isNotEmpty())
            ->values();

        return response()->json([
            'success' => true,
            'data' => $categories->map(fn ($cat) => [
                'id'        => $cat->id,
                'name'      => $cat->name,
                'materials' => $cat->materials->map(fn ($m) => [
                    'id'        => $m->id,
                    'name'      => $m->name,
                    'file_type' => $m->file_type,
                    'file_size' => $m->file_size_formatted,
                    // SEBELUMNYA Storage::disk('public')->url() langsung —
                    // cuma path relatif tanpa domain, sama persis bug yang
                    // sudah diperbaiki di NewsController/CaseStudyController
                    // (lihat trait ResolvesPublicFileUrl). Controller ini
                    // luput tidak ikut dimigrasikan.
                    'url'       => $this->fullImageUrl($m->file),
                ]),
            ]),
        ]);
    }
}
