<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\EmployeeTraining;
use App\Models\PerformanceReview;
use App\Models\PpeIssuance;
use App\Support\SensitiveFiles;
use Illuminate\Http\Request;

/**
 * K3 dan penilaian milik sendiri (Karyawan Fase 5, 2026-11-02): APD yang dipegang beserta jatuh tempo ganti, pelatihan, dan penilaian
 * kinerja. Hanya baca kecuali menyatakan sudah membaca penilaian. Tanpa hasMenuAccess() (sama pola Slip Gaji); data toko lama tidak
 * disembunyikan StoreScope karena difilter user_id.
 */
class SafetyController extends Controller
{
    /**
     * GET /api/staff/safety
     */
    public function show(Request $request)
    {
        $user = $request->user('api');

        $ppe = PpeIssuance::withoutGlobalScopes()->where('user_id', $user->id)->whereNull('returned_on')
            ->with('type:id,name')->orderBy('replace_due_on')->get()
            ->map(fn (PpeIssuance $i) => [
                'id' => $i->id,
                'name' => $i->type?->name,
                'quantity' => $i->quantity,
                'issued_on' => $i->issued_on->toDateString(),
                'replace_due_on' => $i->replace_due_on?->toDateString(),
                'overdue' => $i->isOverdue(),
            ])->values();

        $trainings = EmployeeTraining::withoutGlobalScopes()->where('user_id', $user->id)->orderByDesc('held_on')->get()
            ->map(fn (EmployeeTraining $t) => [
                'id' => $t->id,
                'category_label' => EmployeeTraining::CATEGORIES[$t->category] ?? $t->category,
                'title' => $t->title,
                'held_on' => $t->held_on->toDateString(),
                'valid_until' => $t->valid_until?->toDateString(),
                'expired' => $t->isExpired(),
                'expires_soon' => $t->expiresSoon(),
                'certificate_url' => SensitiveFiles::url($t->certificate_path),
            ])->values();

        $reviews = PerformanceReview::withoutGlobalScopes()->where('user_id', $user->id)->with('reviewer:id,name')
            ->orderByDesc('reviewed_on')->get()
            ->map(fn (PerformanceReview $r) => [
                'id' => $r->id,
                'period_label' => $r->period_label,
                'reviewed_on' => $r->reviewed_on->toDateString(),
                'reviewer_name' => $r->reviewer?->name,
                'scores' => collect(PerformanceReview::ASPECTS)->map(fn (string $label, string $column) => ['label' => $label, 'score' => (int) $r->{$column}])->values(),
                'average' => $r->average(),
                'strengths' => $r->strengths,
                'improvements' => $r->improvements,
                'goals' => $r->goals,
                'acknowledged_at' => $r->acknowledged_at?->toIso8601String(),
            ])->values();

        return response()->json(['success' => true, 'ppe' => $ppe, 'trainings' => $trainings, 'reviews' => $reviews]);
    }

    /**
     * POST /api/staff/performance-reviews/{id}/acknowledge
     * Hanya penilaian milik sendiri; idempotent (pembacaan pertama yang berlaku).
     */
    public function acknowledgeReview(Request $request, int $id)
    {
        $review = PerformanceReview::withoutGlobalScopes()->where('user_id', $request->user('api')->id)->find($id);

        if (! $review) {
            return response()->json(['success' => false, 'message' => 'Penilaian tidak ditemukan.'], 404);
        }

        if ($review->acknowledged_at === null) {
            $review->update(['acknowledged_at' => now()]);
        }

        return response()->json(['success' => true, 'acknowledged_at' => $review->fresh()->acknowledged_at->toIso8601String()]);
    }
}
