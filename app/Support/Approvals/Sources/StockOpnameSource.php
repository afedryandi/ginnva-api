<?php

namespace App\Support\Approvals\Sources;

use App\Filament\Resources\StockOpnameResource;
use App\Models\StockOpname;
use App\Models\User;
use App\Support\Approvals\ApprovalItem;
use Illuminate\Support\Collection;

/**
 * Draf Stok Opname yang menunggu diterapkan (2026-11-17). Hanya akses penuh yang memutuskan, dan keputusannya sengaja TIDAK satu klik:
 * tombol Buka membawa ke rincian sesi supaya selisih per item ditinjau dulu, lalu Terapkan atau Buang Draf dari sana.
 */
class StockOpnameSource extends BaseSource
{
    public function key(): string
    {
        return 'stock_opname';
    }

    public function label(): string
    {
        return 'Stok Opname';
    }

    public function icon(): string
    {
        return 'heroicon-o-clipboard-document-check';
    }

    public function items(User $user): Collection
    {
        if (! $user->isFullAccess()) {
            return $this->none();
        }

        return StockOpname::withoutGlobalScopes()
            ->with(['creator:id,name', 'store:id,name'])
            ->withCount(['items', 'items as diff_count' => fn ($q) => $q->where('delta', '!=', 0)])
            ->where('status', StockOpname::STATUS_DRAFT)
            ->orderBy('created_at')
            ->get()
            ->map(fn (StockOpname $o) => new ApprovalItem(
                source: $this->key(),
                id: $o->id,
                typeLabel: $this->label(),
                icon: $this->icon(),
                who: $o->creator?->name ?? '—',
                summary: "{$o->opname_number} · {$o->items_count} item dihitung, {$o->diff_count} berselisih",
                store: $o->store?->name,
                submittedAt: $o->created_at,
                url: $this->url(StockOpnameResource::class, $o->id),
                inline: false,
            ))
            ->values();
    }
}
