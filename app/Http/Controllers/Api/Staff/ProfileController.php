<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Profil Saya (Karyawan Fase 2, 2026-10-30): satu layar bagi karyawan untuk melihat data kepegawaiannya sendiri -- data diri, masa kerja,
 * kontrak, sisa cuti, dan daftar dokumen. Hanya baca dan hanya milik sendiri (sama pola dengan Slip Gaji dan Surat Peringatan, tanpa
 * hasMenuAccess()). Nomor identitas dikirim tersamar (mis. 3174******0001) karena layar HP mudah dilihat orang lain; nomor lengkap
 * tetap hanya di panel.
 */
class ProfileController extends Controller
{
    /**
     * GET /api/staff/profile
     */
    public function show(Request $request)
    {
        $user = $request->user('api');
        $user->loadMissing(['store:id,name', 'employeeType:id,name,has_end_date']);

        $year = Carbon::now()->year;
        $quota = LeaveRequest::annualQuotaFor($user, $year);
        $used = LeaveRequest::usedCutiDaysFor($user, $year);

        return response()->json([
            'success' => true,
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'employee_number' => $user->employee_number,
                'role' => $user->primaryRoleName(),
                'roles' => $user->getRoleNames()->sort()->values()->all(),
                'store_name' => $user->store?->name,
                'employment' => [
                    'type' => $user->employeeType?->name,
                    'join_date' => $user->join_date?->toDateString(),
                    'tenure_label' => $this->tenureLabel($user->join_date),
                    // Tanggal akhir kontrak hanya berarti bagi karyawan bertipe kontrak; karyawan tetap tidak menampilkannya.
                    'contract_end_date' => $user->employeeType?->has_end_date ? $user->contract_end_date?->toDateString() : null,
                    'contract_days_left' => $user->employeeType?->has_end_date && $user->contract_end_date ? (int) Carbon::today()->diffInDays($user->contract_end_date, false) : null,
                    'contract_extensions' => $user->contractExtensions()->count(),
                    // Tanggal-tanggal kontrak karyawan sendiri, terbaru di atas (tanpa catatan dan pencatat, itu urusan HR); hanya tipe kontrak.
                    'contract_history' => $user->employeeType?->has_end_date
                        ? $user->contractExtensions()->reorder()->orderByDesc('id')->limit(10)->get(['previous_end_date', 'new_end_date'])->map(fn (\App\Models\ContractExtension $e) => [
                            'previous_end_date' => $e->previous_end_date?->toDateString(),
                            'new_end_date' => $e->new_end_date->toDateString(),
                        ])->values()->all()
                        : [],
                    'probation_end_date' => $user->probation_end_date?->toDateString(),
                ],
                'identity' => [
                    'nik' => $this->mask($user->nik),
                    'npwp' => $this->mask($user->npwp),
                    'bpjs_kesehatan_number' => $this->mask($user->bpjs_kesehatan_number),
                    'bpjs_ketenagakerjaan_number' => $this->mask($user->bpjs_ketenagakerjaan_number),
                    'bank_name' => $user->bank_name,
                    'bank_account_number' => $this->mask($user->bank_account_number),
                ],
                // Data yang belum lengkap dan bisa diserahkan karyawan ke HR (banner di layar); tidak bisa diubah dari aplikasi.
                'missing_data' => app(\App\Services\EmployeeDataCompletenessService::class)->employeeFacingGaps($user),
                'emergency_contact' => [
                    'name' => $user->emergency_contact_name,
                    'phone' => $user->emergency_contact_phone,
                    'relationship' => $user->emergency_contact_relationship,
                ],
                'leave' => [
                    'year' => $year,
                    'annual_quota' => $quota,
                    'annual_used' => $used,
                    'annual_remaining' => max(0, $quota - $used),
                    'pending_requests' => LeaveRequest::where('user_id', $user->id)->where('status', 'pending')->count(),
                    // Cuti khusus yang tidak memotong jatah tahunan, dihitung dari yang sudah disetujui tahun ini.
                    'other_used' => $this->otherLeaveUsed($user->id, $year),
                ],
                'unread_warning_letters' => \App\Models\WarningLetter::unreadCountFor($user->id),
                // Dokumen kebijakan yang wajib diterima tetapi belum (Karyawan Fase 3).
                'pending_policies' => \App\Models\CompanyPolicy::published()->where('requires_acknowledgment', true)
                    ->whereNotIn('id', $user->policyAcknowledgments()->select('company_policy_id'))->count(),
                'documents' => $user->documents()->latest()->get()->map(fn (EmployeeDocument $d) => [
                    'id' => $d->id,
                    'type' => $d->type,
                    'type_label' => EmployeeDocument::TYPE_LABELS[$d->type] ?? $d->type,
                    'filename' => $d->original_filename,
                    'uploaded_at' => $d->created_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    /**
     * Pemakaian jenis cuti/izin/sakit yang tidak memotong jatah tahunan, per jenis, hanya pengajuan yang disetujui tahun ini.
     */
    private function otherLeaveUsed(int $userId, int $year): array
    {
        return LeaveRequest::where('user_id', $userId)
            ->where('status', 'approved')
            ->whereYear('start_date', $year)
            ->with('leaveType:id,name,deducts_annual_quota')
            ->get()
            ->filter(fn (LeaveRequest $r) => ! $r->deductsAnnualQuota())
            ->groupBy(fn (LeaveRequest $r) => $r->leaveType?->name ?? ucfirst($r->type))
            ->map(fn ($rows, $name) => ['name' => $name, 'days' => $rows->sum(fn (LeaveRequest $r) => $r->dayCount())])
            ->values()
            ->all();
    }

    private function tenureLabel(?Carbon $joinDate): ?string
    {
        if (! $joinDate || $joinDate->isFuture()) {
            return null;
        }

        $diff = $joinDate->diff(Carbon::today());
        $parts = [];

        if ($diff->y > 0) {
            $parts[] = "{$diff->y} tahun";
        }

        if ($diff->m > 0) {
            $parts[] = "{$diff->m} bulan";
        }

        return $parts ? implode(' ', $parts) : 'Kurang dari 1 bulan';
    }

    /** Tampilkan hanya empat karakter pertama dan terakhir; nomor pendek disamarkan seluruhnya kecuali dua digit terakhir. */
    private function mask(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $length = mb_strlen($value);

        if ($length <= 8) {
            return str_repeat('*', max(0, $length - 2)) . mb_substr($value, -2);
        }

        return mb_substr($value, 0, 4) . str_repeat('*', $length - 8) . mb_substr($value, -4);
    }
}
