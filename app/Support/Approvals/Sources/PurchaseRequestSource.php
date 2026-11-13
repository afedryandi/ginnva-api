<?php

namespace App\Support\Approvals\Sources;

use App\Filament\Resources\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\PurchaseRequestDecisionService;
use App\Support\Approvals\ApprovalItem;
use Illuminate\Support\Collection;

class PurchaseRequestSource extends BaseSource
{
    public function key(): string
    {
        return 'purchase_request';
    }

    public function label(): string
    {
        return 'Permohonan Pembelian';
    }

    public function icon(): string
    {
        return 'heroicon-o-shopping-cart';
    }

    public function inline(): bool
    {
        return true;
    }

    public function items(User $user): Collection
    {
        if (! $user->isFullAccess()) {
            return $this->none();
        }

        return PurchaseRequest::withoutGlobalScopes()
            ->with(['requester:id,name', 'store:id,name'])
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get()
            ->map(fn (PurchaseRequest $r) => new ApprovalItem(
                source: $this->key(),
                id: $r->id,
                typeLabel: $this->label(),
                icon: $this->icon(),
                who: $r->requester?->name ?? '—',
                summary: "{$r->request_number} · {$r->item_name} · " . rtrim(rtrim(number_format((float) $r->quantity, 2, ',', '.'), '0'), ',') . ($r->unit ? " {$r->unit}" : '')
                    . ($r->reason ? " · {$r->reason}" : ''),
                store: $r->store?->name,
                submittedAt: $r->created_at,
                url: $this->url(PurchaseRequestResource::class, $r->id),
                inline: true,
                extra: ['quantity' => (float) $r->quantity, 'unit' => $r->unit],
            ))
            ->values();
    }

    public function approve(int $id, User $by, array $data = []): string
    {
        $quantity = isset($data['quantity_approved']) && $data['quantity_approved'] !== ''
            ? (float) $data['quantity_approved']
            : (float) PurchaseRequest::withoutGlobalScopes()->findOrFail($id)->quantity;

        app(PurchaseRequestDecisionService::class)->approve($id, $by, $quantity);

        return 'Permohonan pembelian disetujui.';
    }

    public function reject(int $id, User $by, string $note): string
    {
        app(PurchaseRequestDecisionService::class)->reject($id, $by, $note);

        return 'Permohonan pembelian ditolak.';
    }
}
