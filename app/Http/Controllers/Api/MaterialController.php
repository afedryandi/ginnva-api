<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class MaterialController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = MaterialCategory::with([
            'materials' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
        ])
            ->orderBy('sort_order')
            ->get();

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
                    'url'       => Storage::disk('public')->url($m->file),
                ]),
            ]),
        ]);
    }
}
