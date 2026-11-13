<?php

namespace App\Support\Approvals\Sources;

use App\Filament\Resources\AttendanceCorrectionRequestResource;
use App\Models\AttendanceCorrectionRequest;
use App\Models\User;
use App\Services\AttendanceCorrectionService;
use App\Support\Approvals\ApprovalItem;
use Illuminate\Support\Collection;

class CorrectionSource extends BaseSource
{
    public function key(): string
    {
        return 'correction';
    }

    public function label(): string
    {
        return 'Koreksi Absen';
    }

    public function icon(): string
    {
        return 'heroicon-o-pencil-square';
    }

    public function inline(): bool
    {
        return true;
    }

    public function items(User $user): Collection
    {
        // Sama dengan menunya: akses penuh, atau store manager (toko sendiri); tidak pernah koreksi milik sendiri.
        return AttendanceCorrectionRequest::withoutGlobalScopes()
            ->with(['user:id,name', 'store:id,name'])
            ->where('status', AttendanceCorrectionRequest::STATUS_PENDING)
            ->where('requested_by', '!=', $user->id)
            ->orderBy('created_at')
            ->get()
            ->filter(fn (AttendanceCorrectionRequest $r) => $user->isFullAccess() || ($user->isStoreManager() && $this->inStore($user, $r->store_id)))
            ->map(fn (AttendanceCorrectionRequest $r) => new ApprovalItem(
                source: $this->key(),
                id: $r->id,
                typeLabel: $this->label(),
                icon: $this->icon(),
                who: $r->user?->name ?? '—',
                summary: $r->date->translatedFormat('d M Y') . ': masuk ' . ($r->clock_in_at?->format('H:i') ?? '—') . ', keluar ' . ($r->clock_out_at?->format('H:i') ?? '—') . ' · ' . $r->reason,
                store: $r->store?->name,
                submittedAt: $r->created_at,
                url: $this->url(AttendanceCorrectionRequestResource::class, $r->id),
                inline: true,
            ))
            ->values();
    }

    public function approve(int $id, User $by, array $data = []): string
    {
        $request = AttendanceCorrectionRequest::withoutGlobalScopes()->findOrFail($id);
        $this->assertMayDecide($by, $request);
        app(AttendanceCorrectionService::class)->approve($request, $by->id);

        return 'Koreksi absen disetujui.';
    }

    public function reject(int $id, User $by, string $note): string
    {
        $request = AttendanceCorrectionRequest::withoutGlobalScopes()->findOrFail($id);
        $this->assertMayDecide($by, $request);
        app(AttendanceCorrectionService::class)->reject($request, $by->id, $note);

        return 'Koreksi absen ditolak.';
    }

    private function assertMayDecide(User $by, AttendanceCorrectionRequest $request): void
    {
        if (! ($by->isFullAccess() || ($by->isStoreManager() && $this->inStore($by, $request->store_id)))) {
            throw new \RuntimeException('Anda tidak berwenang memutuskan koreksi absen ini.');
        }
    }
}
