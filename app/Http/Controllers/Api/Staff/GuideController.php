<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Support\GuideCatalog;
use Illuminate\Http\Request;

/**
 * Panduan Penggunaan di aplikasi staf (Panduan Fase 3, 2026-10-22). Isinya sama dengan halaman Panduan di panel: GuideCatalog::forUser()
 * dipakai kedua sisi. Teks memakai **tebal** sederhana; aplikasi yang menampilkannya. Tanpa hasMenuAccess() terpisah karena penyaringan
 * sudah ada di katalog (installer hanya mendapat panduan aplikasi).
 */
class GuideController extends Controller
{
    /**
     * GET /api/staff/guides
     */
    public function index(Request $request)
    {
        $guides = collect(GuideCatalog::forUser($request->user('api')))
            ->map(fn (array $guide) => [
                'id' => $guide['id'],
                'title' => $guide['title'],
                'summary' => $guide['summary'],
                'steps' => $guide['steps'],
                'tips' => $guide['tips'],
                'category' => $guide['category'],
                'category_label' => GuideCatalog::CATEGORIES[$guide['category']] ?? 'Lainnya',
                'note' => $guide['note'] ?? null,
                'version' => $guide['version'],
                'reviewed_at' => $guide['reviewed_at'],
                'overdue' => GuideCatalog::isOverdue($guide),
            ])
            ->values();

        return response()->json(['success' => true, 'guides' => $guides]);
    }
}
