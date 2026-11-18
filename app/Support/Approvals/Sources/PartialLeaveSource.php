<?php

namespace App\Support\Approvals\Sources;

use App\Filament\Resources\PartialLeaveRequestResource;
use App\Models\PartialLeaveRequest;
use App\Models\User;
use App\Services\PartialLeaveRequestService;
use App\Support\Approvals\ApprovalItem;
use Illuminate\Support\Collection;

class PartialLeaveSource extends BaseSource
{
    public function key(): string
    {
        return 'partial_leave';
    }

    public function label(): string
    {
        return 'Izin Sebagian Hari';
    }

    public function icon(): string
    {
        return 'heroicon-o-clock';
    }

    public function inline(): bool
    {
        return true;
    }

    public function items(User $user): Collection
    {
        $service = app(PartialLeaveRequestService::class);

        return PartialLeaveRequest::withoutGlobalScopes()
            ->with(['user:id,name', 'store:id,name'])
            ->where('status', PartialLeaveRequest::STATUS_PENDING)
            ->orderBy('created_at')
            ->get()
            ->filter(fn (PartialLeaveRequest $r) => $service->canDecide($user, $r))
            ->map(fn (PartialLeaveRequest $r) => new ApprovalItem(
                source: $this->key(),
                id: $r->id,
                typeLabel: $this->label(),
                icon: $this->icon(),
                who: $r->user?->name ?? '—',
                summary: $r->categoryLabel() . ', ' . $r->date->translatedFormat('d M Y') . ' (' . $r->windowLabel() . ') · ' . $r->reason,
                store: $r->store?->name,
                submittedAt: $r->created_at,
                url: $this->url(PartialLeaveRequestResource::class, $r->id),
                inline: true,
            ))
            ->values();
    }

    public function approve(int $id, User $by, array $data = []): string
    {
        $request = PartialLeaveRequest::withoutGlobalScopes()->findOrFail($id);
        app(PartialLeaveRequestService::class)->approve($request, $by);

        return 'Izin sebagian hari disetujui.';
    }

    public function reject(int $id, User $by, string $note): string
    {
        $request = PartialLeaveRequest::withoutGlobalScopes()->findOrFail($id);
        app(PartialLeaveRequestService::class)->reject($request, $by, $note);

        return 'Izin sebagian hari ditolak.';
    }
}
