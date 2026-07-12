<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOpening;
use Illuminate\Http\JsonResponse;

class JobOpeningController extends Controller
{
    /**
     * GET /api/job-openings
     *
     * Daftar lowongan kerja yang ditampilkan di halaman "/karier" web
     * (sebelumnya hardcoded di CareerContent.tsx). Hanya yang
     * is_published = true, diurutkan sesuai sort_order (drag-reorder di
     * Filament).
     */
    public function index(): JsonResponse
    {
        $jobs = JobOpening::query()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (JobOpening $job) => [
                'id'           => $job->id,
                'title'        => $job->title,
                'department'   => $job->department,
                'location'     => $job->location,
                'type'         => $job->type,
                'description'  => $job->description,
                'requirements' => $job->requirements ?? [],
            ]);

        return response()->json([
            'success' => true,
            'data'    => $jobs,
        ]);
    }
}
