<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Keputusan Permohonan Pembelian satu pintu (2026-11-13): dipakai tombol Setujui/Tolak di menu Permohonan Pembelian dan di Pusat Persetujuan.
 * Hanya akses penuh yang boleh memutuskan. Status dicek ulang pada baris yang dikunci supaya klik ganda atau dua pemutus bersamaan aman.
 */
class PurchaseRequestDecisionService
{
    public function approve(int $id, User $by, float $quantityApproved): PurchaseRequest
    {
        $this->assertMayDecide($by);

        DB::transaction(function () use ($id, $by, $quantityApproved) {
            $locked = PurchaseRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new RuntimeException('Permohonan sudah diproses.');
            }

            $approved = round($quantityApproved, 2);

            if ($approved <= 0 || $approved > round((float) $locked->quantity, 2)) {
                throw new RuntimeException('Jumlah yang disetujui harus lebih dari 0 dan tidak melebihi jumlah yang diminta.');
            }

            $locked->update([
                'status' => 'approved',
                // Kosong bila disetujui sebesar yang diminta.
                'quantity_approved' => $approved < round((float) $locked->quantity, 2) ? $approved : null,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
            ]);
        });

        $record = PurchaseRequest::query()->findOrFail($id);

        if ($record->requested_by) {
            app(StaffNotifier::class)->toUsers(
                [$record->requested_by],
                'Permohonan Pembelian Disetujui',
                "Permohonan {$record->request_number} ({$record->item_name}) disetujui"
                    . ($record->quantity_approved !== null
                        ? ' dengan jumlah ' . $this->number($record->quantity_approved) . ($record->unit ? " {$record->unit}" : '') . ' (diminta ' . $this->number($record->quantity) . ').'
                        : '.')
            );
        }

        return $record;
    }

    public function reject(int $id, User $by, string $note): PurchaseRequest
    {
        $this->assertMayDecide($by);

        if (trim($note) === '') {
            throw new RuntimeException('Alasan penolakan wajib diisi.');
        }

        DB::transaction(function () use ($id, $by, $note) {
            $locked = PurchaseRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw new RuntimeException('Permohonan sudah diproses.');
            }

            $locked->update([
                'status' => 'rejected',
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);
        });

        $record = PurchaseRequest::query()->findOrFail($id);

        if ($record->requested_by) {
            app(StaffNotifier::class)->toUsers(
                [$record->requested_by],
                'Permohonan Pembelian Ditolak',
                "Permohonan {$record->request_number} ({$record->item_name}) ditolak: {$note}"
            );
        }

        return $record;
    }

    private function assertMayDecide(User $by): void
    {
        if (! $by->isFullAccess()) {
            throw new RuntimeException('Hanya akses penuh yang boleh memutuskan Permohonan Pembelian.');
        }
    }

    private function number(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    }
}
