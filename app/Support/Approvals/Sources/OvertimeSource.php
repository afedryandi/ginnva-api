<?php

namespace App\Support\Approvals\Sources;

use App\Filament\Resources\OvertimeRequestResource;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\OvertimeRequestService;
use App\Support\Approvals\ApprovalItem;
use Illuminate\Support\Collection;

class OvertimeSource extends BaseSource
{
    public function key(): string
    {
        return 'overtime';
    }

    public function label(): string
    {
        return 'Lembur';
    }

    public function icon(): string
    {
        return 'heroicon-o-moon';
    }

    public function inline(): bool
    {
        return true;
    }

    public function items(User $user): Collection
    {
        $service = app(OvertimeRequestService::class);

        return OvertimeRequest::withoutGlobalScopes()
            ->with(['user:id,name', 'store:id,name', 'booking:id,booking_number'])
            ->where('status', OvertimeRequest::STATUS_PENDING)
            ->orderBy('created_at')
            ->get()
            ->filter(fn (OvertimeRequest $r) => $service->canDecide($user, $r))
            ->map(fn (OvertimeRequest $r) => new ApprovalItem(
                source: $this->key(),
                id: $r->id,
                typeLabel: $this->label(),
                icon: $this->icon(),
                who: $r->user?->name ?? '—',
                summary: $r->starts_at->translatedFormat('d M Y') . ' pukul ' . $r->starts_at->format('H:i') . '–' . $r->ends_at->format('H:i') . ' · ' . $r->workLabel(),
                store: $r->store?->name,
                submittedAt: $r->created_at,
                url: $this->url(OvertimeRequestResource::class, $r->id),
                inline: true,
            ))
            ->values();
    }

    public function approve(int $id, User $by, array $data = []): string
    {
        $request = OvertimeRequest::withoutGlobalScopes()->findOrFail($id);
        app(OvertimeRequestService::class)->approve($request, $by);

        return 'Lembur disetujui.';
    }

    public function reject(int $id, User $by, string $note): string
    {
        $request = OvertimeRequest::withoutGlobalScopes()->findOrFail($id);
        app(OvertimeRequestService::class)->reject($request, $by, $note);

        return 'Lembur ditolak.';
    }
}
