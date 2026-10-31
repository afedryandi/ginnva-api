<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\CompanyPolicy;
use App\Support\SensitiveFiles;
use Illuminate\Http\Request;

/**
 * Peraturan Perusahaan, kebijakan, dan SOP yang terbit, beserta penerimaan karyawan (Karyawan Fase 3, 2026-10-31). Milik sendiri dan tanpa
 * hasMenuAccess() (sama pola Slip Gaji dan Surat Peringatan): semua karyawan wajib bisa membaca aturan yang mengikatnya. Berkas diberikan
 * lewat tautan sementara dari disk privat.
 */
class PolicyController extends Controller
{
    /**
     * GET /api/staff/policies
     */
    public function index(Request $request)
    {
        $user = $request->user('api');
        $accepted = $user->policyAcknowledgments()->pluck('acknowledged_at', 'company_policy_id');

        // Dokumen terbit, ditambah dokumen yang sudah pernah diterima karyawan ini walau kemudian dicabut terbit (is_published false), supaya
        // aturan yang dulu ia terima tetap bisa dibuka lagi.
        $policies = CompanyPolicy::query()
            ->where(fn ($q) => $q->where('is_published', true)->orWhereIn('id', $accepted->keys()))
            ->orderByRaw("FIELD(category, 'peraturan_perusahaan', 'kebijakan', 'sop')")
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CompanyPolicy $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'category' => $p->category,
                'category_label' => CompanyPolicy::CATEGORIES[$p->category] ?? $p->category,
                'version' => $p->version,
                'effective_from' => $p->effective_from?->toDateString(),
                'description' => $p->description,
                'file_url' => SensitiveFiles::url($p->file_path),
                'requires_acknowledgment' => $p->requires_acknowledgment,
                'is_published' => $p->is_published,
                'acknowledged_at' => isset($accepted[$p->id]) ? $accepted[$p->id]->toIso8601String() : null,
            ])
            ->values();

        return response()->json(['success' => true, 'policies' => $policies]);
    }

    /**
     * POST /api/staff/policies/{id}/acknowledge
     * Karyawan menyatakan sudah membaca dan menerima. Idempotent; hanya dokumen yang terbit dan mensyaratkan penerimaan.
     */
    public function acknowledge(Request $request, int $id)
    {
        $policy = CompanyPolicy::published()->where('requires_acknowledgment', true)->find($id);

        if (! $policy) {
            return response()->json(['success' => false, 'message' => 'Dokumen tidak ditemukan.'], 404);
        }

        $ack = $policy->acknowledge($request->user('api'), $request->ip());

        return response()->json(['success' => true, 'acknowledged_at' => $ack->acknowledged_at->toIso8601String()]);
    }
}
