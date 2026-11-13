<?php

namespace App\Support\Approvals\Sources;

use App\Filament\Resources\TransactionApprovalRequestResource;
use App\Models\TransactionApprovalRequest;
use App\Models\User;
use App\Services\TransactionApprovalService;
use App\Support\Approvals\ApprovalItem;
use Illuminate\Support\Collection;

/** Persetujuan Transaksi: Catat Penjualan, Proses Refund, dan Catat Uang Muka dari staf non-akses-penuh. Hanya akses penuh yang memutuskan. */
class TransactionSource extends BaseSource
{
    public function key(): string
    {
        return 'transaction';
    }

    public function label(): string
    {
        return 'Persetujuan Transaksi';
    }

    public function icon(): string
    {
        return 'heroicon-o-banknotes';
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

        return TransactionApprovalRequest::query()
            ->with(['booking', 'requester:id,name'])
            ->where('status', 'pending')
            ->where('requested_by', '!=', $user->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (TransactionApprovalRequest $r) => new ApprovalItem(
                source: $this->key(),
                id: $r->id,
                typeLabel: TransactionApprovalRequest::TYPE_LABELS[$r->type] ?? $this->label(),
                icon: $this->icon(),
                who: $r->requester?->name ?? '—',
                summary: 'Booking ' . ($r->booking?->booking_number ?? '—') . ($r->booking?->customer_name ? " ({$r->booking->customer_name})" : '') . ' · Rp' . number_format($r->amount(), 0, ',', '.'),
                store: null,
                submittedAt: $r->created_at,
                url: $this->url(TransactionApprovalRequestResource::class, $r->id),
                inline: true,
            ))
            ->values();
    }

    public function approve(int $id, User $by, array $data = []): string
    {
        $request = TransactionApprovalRequest::query()->findOrFail($id);
        $warnings = app(TransactionApprovalService::class)->approve($request, $by->id);

        return 'Pengajuan transaksi disetujui.' . ($warnings !== [] ? ' ' . implode(' ', array_map('strval', $warnings)) : '');
    }

    public function reject(int $id, User $by, string $note): string
    {
        $request = TransactionApprovalRequest::query()->findOrFail($id);
        app(TransactionApprovalService::class)->reject($request, $by->id, $note);

        return 'Pengajuan transaksi ditolak.';
    }
}
