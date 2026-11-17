<?php

namespace App\Support\Approvals\Sources;

use App\Filament\Resources\MaterialMemoResource;
use App\Models\MaterialMemo;
use App\Models\User;
use App\Services\MaterialMemoRequestService;
use App\Support\Approvals\ApprovalItem;
use Illuminate\Support\Collection;

/**
 * Pengajuan memo barang yang menunggu persetujuan store manager (2026-11-17). Disetujui atau ditolak langsung dari Pusat Persetujuan lewat
 * MaterialMemoRequestService yang sama dengan aplikasi dan panel Memo. Tahap penyerahan oleh gudang ada di MaterialMemoHandoverSource.
 */
class MaterialMemoSource extends BaseSource
{
    public function key(): string
    {
        return 'material_memo';
    }

    public function label(): string
    {
        return 'Memo Barang';
    }

    public function icon(): string
    {
        return 'heroicon-o-clipboard-document-list';
    }

    public function inline(): bool
    {
        return true;
    }

    public function items(User $user): Collection
    {
        $service = app(MaterialMemoRequestService::class);

        return MaterialMemo::withoutGlobalScopes()
            ->with(['creator:id,name', 'store:id,name'])
            ->where('request_status', MaterialMemo::REQ_PENDING)
            ->orderBy('submitted_at')
            ->get()
            ->filter(fn (MaterialMemo $m) => $service->canApprove($user, $m))
            ->map(fn (MaterialMemo $m) => new ApprovalItem(
                source: $this->key(),
                id: $m->id,
                typeLabel: $this->label(),
                icon: $this->icon(),
                who: $m->creator?->name ?? '—',
                summary: collect([$m->memo_number, $m->vehicle_info, $m->spk_number ? "SPK {$m->spk_number}" : null, $m->request_note])->filter()->implode(' · '),
                store: $m->store?->name,
                submittedAt: $m->submitted_at ?? $m->created_at,
                url: $this->url(MaterialMemoResource::class, $m->id),
                inline: true,
            ))
            ->values();
    }

    public function approve(int $id, User $by, array $data = []): string
    {
        $memo = MaterialMemo::withoutGlobalScopes()->findOrFail($id);

        try {
            app(MaterialMemoRequestService::class)->approve($memo, $by);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage());
        }

        return 'Memo barang disetujui. Gudang akan mencatat barang yang diambil.';
    }

    public function reject(int $id, User $by, string $note): string
    {
        $memo = MaterialMemo::withoutGlobalScopes()->findOrFail($id);

        try {
            app(MaterialMemoRequestService::class)->reject($memo, $by, $note);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage());
        }

        return 'Memo barang ditolak.';
    }
}
