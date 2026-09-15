<?php

namespace App\Services;

use App\Models\FinanceTransaction;
use App\Models\FinanceTransactionApprovalRequest;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fase 4 — Kontrol & Kepatuhan (diminta user 2026-09-15): satu-satunya
 * jalur resmi submit/approve/reject pengajuan PENGELUARAN (type='out')
 * Transaksi Keuangan dari staff non-full-access.
 *
 * Alur: staff toko ajukan -> store_manager approve (tahap 1) ->
 * direksi approve (tahap 2, WAJIB untuk SEMUA nominal, tidak ada
 * ambang skip) -> baru FinanceTransaction sungguhan dibuat + diposting
 * ke Jurnal Umum. store_manager yang mengajukan SENDIRI otomatis
 * lompat ke tahap 2 (auto-lolos tahap 1, tidak perlu store_manager
 * toko lain approve). User full-access (super_admin/direksi) TIDAK
 * lewat service ini sama sekali -- lihat CreateFinanceTransaction,
 * mereka tetap catat langsung seperti sebelumnya (self-approval tidak
 * menambah kontrol apa pun).
 */
class FinanceTransactionApprovalService
{
    public function submit(array $data, User $requester): FinanceTransactionApprovalRequest
    {
        $status = $requester->isStoreManager() ? 'pending_direksi' : 'pending_manager';

        $request = FinanceTransactionApprovalRequest::create([
            'payload' => $data,
            'status' => $status,
            'requested_by' => $requester->id,
        ]);

        $this->notifyNextApprovers($request);

        return $request;
    }

    /**
     * @throws RuntimeException kalau status bukan pending_manager, atau
     *         approver bukan store_manager toko yang sama / full-access.
     */
    public function approveByManager(FinanceTransactionApprovalRequest $request, User $approver): void
    {
        if (! $request->isPendingManager()) {
            throw new RuntimeException('Pengajuan ini bukan lagi menunggu persetujuan Store Manager.');
        }

        $isFullAccess = $approver->isFullAccess();
        $isSameStoreManager = $approver->isStoreManager() && $approver->store_id === $request->store_id_from_payload;

        if (! $isFullAccess && ! $isSameStoreManager) {
            throw new RuntimeException('Cuma Store Manager toko yang sama (atau full-access) yang boleh menyetujui tahap ini.');
        }

        $request->update([
            'status' => 'pending_direksi',
            'manager_approved_by' => $approver->id,
            'manager_approved_at' => now(),
        ]);

        $this->notifyNextApprovers($request);
    }

    /**
     * @throws RuntimeException kalau status bukan pending_direksi, atau
     *         approver bukan full-access, ATAU diteruskan dari
     *         FinanceTransactionPostingService (mis. kategori belum
     *         dihubungkan ke Bagan Akun, periode sudah ditutup).
     */
    public function approveByDireksi(FinanceTransactionApprovalRequest $request, User $approver): FinanceTransaction
    {
        if (! $request->isPendingDireksi()) {
            throw new RuntimeException('Pengajuan ini bukan lagi menunggu persetujuan Direksi.');
        }

        if (! $approver->isFullAccess()) {
            throw new RuntimeException('Cuma Direksi/Super Admin yang boleh menyetujui tahap ini.');
        }

        return DB::transaction(function () use ($request, $approver) {
            $data = $request->payload;
            $data['created_by'] = $request->requested_by;

            $transaction = FinanceTransaction::create($data);
            $entry = app(FinanceTransactionPostingService::class)->post($transaction);
            $transaction->update(['journal_entry_id' => $entry->id]);

            $request->update([
                'status' => 'approved',
                'direksi_approved_by' => $approver->id,
                'direksi_approved_at' => now(),
                'finance_transaction_id' => $transaction->id,
            ]);

            return $transaction;
        });
    }

    /**
     * @throws RuntimeException kalau pengajuan sudah final (approved/rejected).
     */
    public function reject(FinanceTransactionApprovalRequest $request, User $approver, ?string $note): void
    {
        if (! in_array($request->status, ['pending_manager', 'pending_direksi'], true)) {
            throw new RuntimeException('Pengajuan ini sudah final, tidak bisa ditolak lagi.');
        }

        $request->update([
            'status' => 'rejected',
            'rejected_by' => $approver->id,
            'rejected_at' => now(),
            'rejection_note' => $note,
        ]);

        Notification::make()
            ->title('Pengajuan pengeluaran ditolak')
            ->body('Nominal Rp' . number_format((float) ($request->payload['amount'] ?? 0), 0, ',', '.') . ($note ? " — {$note}" : ''))
            ->danger()
            ->sendToDatabase(User::find($request->requested_by));
    }

    private function notifyNextApprovers(FinanceTransactionApprovalRequest $request): void
    {
        $amountLabel = 'Rp' . number_format((float) ($request->payload['amount'] ?? 0), 0, ',', '.');

        if ($request->isPendingManager()) {
            $storeId = $request->store_id_from_payload;
            $recipients = User::where('store_id', $storeId)->get()->filter(fn (User $u) => $u->isStoreManager());
            $title = "Menunggu persetujuan Anda: pengeluaran {$amountLabel}";
        } else {
            $recipients = User::where('is_active', true)->get()->filter(fn (User $u) => $u->isFullAccess());
            $title = "Menunggu persetujuan direksi: pengeluaran {$amountLabel}";
        }

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title($title)
                ->warning()
                ->sendToDatabase($recipient);
        }
    }
}
