<?php

namespace App\Support\Approvals\Sources;

use App\Filament\Resources\MaterialMemoResource;
use App\Models\MaterialMemo;
use App\Models\User;
use App\Services\MaterialMemoRequestService;
use App\Support\Approvals\ApprovalItem;
use Illuminate\Support\Collection;

/**
 * Memo barang yang sudah disetujui dan menunggu gudang mencatat barang yang diambil lalu menekan Selesai diserahkan. Hanya tautan: gudang
 * mengisi barang dan jumlahnya di halaman memo (atau di aplikasi), bukan satu klik.
 */
class MaterialMemoHandoverSource extends BaseSource
{
    public function key(): string
    {
        return 'material_memo_handover';
    }

    public function label(): string
    {
        return 'Memo Barang: Penyerahan';
    }

    public function icon(): string
    {
        return 'heroicon-o-archive-box-arrow-down';
    }

    public function items(User $user): Collection
    {
        $service = app(MaterialMemoRequestService::class);

        return MaterialMemo::withoutGlobalScopes()
            ->with(['creator:id,name', 'store:id,name'])
            ->where('request_status', MaterialMemo::REQ_APPROVED)
            ->orderBy('approved_at')
            ->get()
            ->filter(fn (MaterialMemo $m) => $service->canHandOver($user, $m) || $this->canGlance($user, (int) $m->store_id, (int) $m->created_by))
            ->map(fn (MaterialMemo $m) => new ApprovalItem(
                source: $this->key(),
                id: $m->id,
                typeLabel: $this->label(),
                icon: $this->icon(),
                who: $m->creator?->name ?? '—',
                summary: collect([$m->memo_number, $m->vehicle_info, 'disetujui, menunggu barang diserahkan'])->filter()->implode(' · '),
                store: $m->store?->name,
                submittedAt: $m->approved_at ?? $m->created_at,
                url: $this->urlFor($user, MaterialMemoResource::class, $m->id),
                inline: false,
            ))
            ->values();
    }
}
