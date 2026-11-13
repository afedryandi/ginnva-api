<?php

namespace App\Support\Approvals\Sources;

use App\Filament\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveDecisionService;
use App\Support\Approvals\ApprovalItem;
use Illuminate\Support\Collection;

class LeaveSource extends BaseSource
{
    public function key(): string
    {
        return 'leave';
    }

    public function label(): string
    {
        return 'Izin & Cuti';
    }

    public function icon(): string
    {
        return 'heroicon-o-calendar-days';
    }

    public function inline(): bool
    {
        return true;
    }

    public function items(User $user): Collection
    {
        return LeaveRequest::withoutGlobalScopes()
            ->with(['user:id,name', 'store:id,name', 'leaveType:id,name'])
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (LeaveRequest $r) => $user->canDecideLeaveFor($r))
            ->map(fn (LeaveRequest $r) => new ApprovalItem(
                source: $this->key(),
                id: $r->id,
                typeLabel: $this->label(),
                icon: $this->icon(),
                who: $r->user?->name ?? '—',
                summary: ($r->leaveType?->name ?? ucfirst((string) $r->type)) . ' · ' . $r->start_date->translatedFormat('d M') . '–' . $r->end_date->translatedFormat('d M Y')
                    . ' (' . $r->dayCount() . ' hari) · ' . $r->reason,
                store: $r->store?->name,
                submittedAt: $r->created_at,
                url: $this->url(LeaveRequestResource::class, $r->id),
                inline: true,
            ))
            ->values();
    }

    public function approve(int $id, User $by, array $data = []): string
    {
        $result = app(LeaveDecisionService::class)->approve($id, $by);

        return 'Izin disetujui.' . ($result['payroll_locked'] ? ' Perhatian: slip gaji bulan terkait sudah menunggu persetujuan atau dibayar; angka gaji tidak otomatis berubah.' : '');
    }

    public function reject(int $id, User $by, string $note): string
    {
        app(LeaveDecisionService::class)->reject($id, $by, $note);

        return 'Izin ditolak.';
    }
}
