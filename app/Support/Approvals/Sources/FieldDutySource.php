<?php

namespace App\Support\Approvals\Sources;

use App\Filament\Resources\FieldDutyRequestResource;
use App\Models\FieldDutyRequest;
use App\Models\User;
use App\Services\FieldDutyRequestService;
use App\Support\Approvals\ApprovalItem;
use Illuminate\Support\Collection;

class FieldDutySource extends BaseSource
{
    public function key(): string
    {
        return 'field_duty';
    }

    public function label(): string
    {
        return 'Dinas Luar';
    }

    public function icon(): string
    {
        return 'heroicon-o-map-pin';
    }

    public function inline(): bool
    {
        return true;
    }

    public function items(User $user): Collection
    {
        $service = app(FieldDutyRequestService::class);

        return FieldDutyRequest::withoutGlobalScopes()
            ->with(['user:id,name', 'store:id,name'])
            ->where('status', FieldDutyRequest::STATUS_PENDING)
            ->orderBy('created_at')
            ->get()
            ->filter(fn (FieldDutyRequest $r) => $service->canDecide($user, $r))
            ->map(fn (FieldDutyRequest $r) => new ApprovalItem(
                source: $this->key(),
                id: $r->id,
                typeLabel: $this->label(),
                icon: $this->icon(),
                who: $r->user?->name ?? '—',
                summary: $r->date->translatedFormat('d M Y') . ' (' . $r->windowLabel() . ') · ' . $r->description,
                store: $r->store?->name,
                submittedAt: $r->created_at,
                url: $this->url(FieldDutyRequestResource::class, $r->id),
                inline: true,
                // Dinas sebagian hari: penyetuju boleh mengubah jam saat menyetujui.
                extra: ['partial' => $r->isPartialDay(), 'start_time' => $r->isPartialDay() ? substr((string) $r->start_time, 0, 5) : null, 'end_time' => $r->isPartialDay() ? substr((string) $r->end_time, 0, 5) : null],
            ))
            ->values();
    }

    public function approve(int $id, User $by, array $data = []): string
    {
        $request = FieldDutyRequest::withoutGlobalScopes()->findOrFail($id);

        $adjust = $request->isPartialDay() && ! empty($data['start_time']) && ! empty($data['end_time'])
            ? ['start_time' => $data['start_time'], 'end_time' => $data['end_time']]
            : null;

        app(FieldDutyRequestService::class)->approve($request, $by, null, $adjust);

        return 'Dinas luar disetujui.';
    }

    public function reject(int $id, User $by, string $note): string
    {
        $request = FieldDutyRequest::withoutGlobalScopes()->findOrFail($id);
        app(FieldDutyRequestService::class)->reject($request, $by, $note);

        return 'Dinas luar ditolak.';
    }
}
