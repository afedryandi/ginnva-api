<?php

namespace App\Services;

use App\Models\CompanyPolicy;
use App\Models\EmployeeTraining;
use App\Models\LeaveRequest;
use App\Models\PerformanceReview;
use App\Models\PpeIssuance;
use App\Models\SafetyIncident;
use App\Models\TechnicianTraining;
use App\Models\User;
use App\Models\WarningLetter;

/**
 * Ringkasan Dasbor HR (Karyawan Fase 5, 2026-11-02): angka yang butuh tindakan HR dalam satu layar. Murni agregat dari data yang sudah
 * ada; tidak menyimpan apa pun. Karyawan aktif = akun aktif selain mitra. Sebuah angka 0 berarti tidak ada yang perlu ditindak.
 */
class HrDashboardService
{
    /**
     * @return array{
     *   headcount: int, by_store: array<string,int>, by_type: array<string,int>,
     *   contracts_expiring: list<array{name:string,date:string}>, contracts_expired: int,
     *   probation_ending: list<array{name:string,date:string}>,
     *   incomplete_required: int, pending_leave: int, active_warnings: int,
     *   policies_pending: list<array{title:string,pending:int}>,
     *   ppe_overdue: int, trainings_expiring: int, open_incidents: int, open_injuries: int, not_reviewed: int
     * }
     */
    public function summary(?int $storeId = null): array
    {
        $employees = fn () => User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId));

        $people = $employees()->with(['store:id,name', 'employeeType:id,name'])->get();
        $userIds = $people->pluck('id');

        $warnDays = (int) config('hr.probation_warning_days', 14);

        $incomplete = app(EmployeeDataCompletenessService::class)->findings($storeId)
            ->filter(fn (array $row) => $row['required'] !== [])
            ->count();

        $policies = CompanyPolicy::published()->where('requires_acknowledgment', true)->get(['id', 'title']);
        $accepted = \App\Models\PolicyAcknowledgment::whereIn('company_policy_id', $policies->pluck('id'))
            ->whereIn('user_id', $userIds)
            ->get(['company_policy_id', 'user_id'])
            ->groupBy('company_policy_id');

        $overdueQuery = PpeIssuance::query()->withoutGlobalScopes()->whereIn('user_id', $userIds)
            ->whereNull('returned_on')->whereNotNull('replace_due_on')->whereDate('replace_due_on', '<=', today());

        $soon = today()->addDays(30)->toDateString();
        $trainingsExpiring = EmployeeTraining::withoutGlobalScopes()->whereIn('user_id', $userIds)
            ->whereNotNull('valid_until')->whereDate('valid_until', '<=', $soon)->count()
            + TechnicianTraining::query()->whereNotNull('valid_until')->whereDate('valid_until', '<=', $soon)
                ->whereHas('technician', fn ($q) => $q->withoutGlobalScopes()->where('status', '!=', 'inactive')->when($storeId, fn ($t) => $t->where('store_id', $storeId)))
                ->count();

        $incidents = SafetyIncident::query()->withoutGlobalScopes()->where('status', 'open')->when($storeId, fn ($q) => $q->where('store_id', $storeId));

        $reviewedRecently = PerformanceReview::query()->withoutGlobalScopes()->whereIn('user_id', $userIds)
            ->whereDate('reviewed_on', '>=', today()->subYear()->toDateString())->distinct()->pluck('user_id');

        return [
            'headcount' => $people->count(),
            'by_store' => $people->groupBy(fn (User $u) => $u->store?->name ?? 'Tanpa toko')->map->count()->sortKeys()->all(),
            'by_type' => $people->groupBy(fn (User $u) => $u->employeeType?->name ?? 'Belum dipilih')->map->count()->sortKeys()->all(),
            'contracts_expiring' => $people->filter(fn (User $u) => $u->contract_end_date && $u->contract_end_date->between(today(), today()->addDays(30)))
                ->sortBy('contract_end_date')->map(fn (User $u) => ['name' => $u->name, 'date' => $u->contract_end_date->format('d M Y')])->values()->all(),
            'contracts_expired' => $people->filter(fn (User $u) => $u->employeeType?->has_end_date && $u->contract_end_date && $u->contract_end_date->isPast())->count(),
            'probation_ending' => $people->filter(fn (User $u) => $u->probation_end_date && $u->probation_end_date->between(today(), today()->addDays($warnDays)))
                ->sortBy('probation_end_date')->map(fn (User $u) => ['name' => $u->name, 'date' => $u->probation_end_date->format('d M Y')])->values()->all(),
            'incomplete_required' => $incomplete,
            'pending_leave' => LeaveRequest::query()->withoutGlobalScopes()->where('status', 'pending')->whereIn('user_id', $userIds)->count(),
            'active_warnings' => WarningLetter::query()->withoutGlobalScopes()->whereIn('user_id', $userIds)->whereDate('valid_until', '>=', today()->toDateString())->count(),
            'policies_pending' => $policies->map(fn ($p) => [
                'title' => $p->title,
                'pending' => $userIds->diff(($accepted[$p->id] ?? collect())->pluck('user_id'))->count(),
            ])->filter(fn (array $row) => $row['pending'] > 0)->values()->all(),
            'ppe_overdue' => $overdueQuery->count(),
            'trainings_expiring' => $trainingsExpiring,
            'open_incidents' => (clone $incidents)->count(),
            'open_injuries' => (clone $incidents)->where('type', 'injury')->count(),
            'not_reviewed' => $userIds->diff($reviewedRecently)->count(),
        ];
    }
}
