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
    /**
     * @throws RuntimeException kalau kategori tidak ada/nonaktif atau belum
     *         terhubung ke akun Bagan Akun. Dicek SAAT PENGAJUAN (audit
     *         Kategori Keuangan 2026-09-28) -- sebelumnya kegagalan baru
     *         muncul saat direksi menyetujui, setelah pengajuan melewati
     *         dua level approval.
     */
    public function submit(array $data, User $requester): FinanceTransactionApprovalRequest
    {
        $category = \App\Models\FinanceCategory::find($data['finance_category_id'] ?? null);

        if (! $category || ! $category->is_active) {
            throw new RuntimeException('Kategori tidak ditemukan atau sudah nonaktif.');
        }

        if (! $category->chart_of_account_id) {
            throw new RuntimeException("Kategori \"{$category->name}\" belum terhubung ke akun Bagan Akun, pengajuan tidak bisa dibuat. Hubungi admin keuangan.");
        }

        // Toko divalidasi di SERVER: non-full-access hanya boleh mengajukan
        // untuk tokonya sendiri (sebelumnya cuma dipaksa di halaman Create).
        if (! $requester->isFullAccess()) {
            $data['store_id'] = $requester->store_id;
        }

        $store = \App\Models\Store::find($data['store_id'] ?? null);
        if (! $store || ! $store->is_active) {
            throw new RuntimeException('Toko tidak ditemukan atau sudah nonaktif.');
        }

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
     * approveByManager/approveByDireksi/reject SEMUANYA memakai
     * lockForUpdate() DI DALAM DB::transaction() dan mengecek ulang status
     * pada baris yang SUDAH dikunci (audit Transaksi Keuangan 2026-09-28) --
     * sebelumnya status dicek pada model basi di luar transaksi, jadi dua
     * direksi (atau double-klik) yang menyetujui bersamaan sama-sama lolos
     * dan membuat dua transaksi + dua jurnal untuk satu pengajuan; approve
     * yang bertabrakan dengan reject bisa saling menimpa.
     *
     * @throws RuntimeException kalau status bukan pending_manager, atau
     *         approver bukan store_manager toko yang sama / full-access.
     */
    public function approveByManager(FinanceTransactionApprovalRequest $request, User $approver): void
    {
        $isFullAccess = $approver->isFullAccess();

        DB::transaction(function () use ($request, $approver, $isFullAccess) {
            $locked = FinanceTransactionApprovalRequest::whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isPendingManager()) {
                throw new RuntimeException('Pengajuan ini bukan lagi menunggu persetujuan Store Manager.');
            }

            $isSameStoreManager = $approver->isStoreManager() && $approver->store_id === $locked->store_id_from_payload;

            if (! $isFullAccess && ! $isSameStoreManager) {
                throw new RuntimeException('Cuma Store Manager toko yang sama (atau full-access) yang boleh menyetujui tahap ini.');
            }

            $locked->update([
                'status' => 'pending_direksi',
                'manager_approved_by' => $approver->id,
                'manager_approved_at' => now(),
            ]);

            $this->notifyNextApprovers($locked);
        });
    }

    /**
     * @throws RuntimeException kalau status bukan pending_direksi, atau
     *         approver bukan full-access, kategori/toko pada pengajuan sudah
     *         tidak valid, ATAU diteruskan dari
     *         FinanceTransactionPostingService (mis. kategori belum
     *         dihubungkan ke Bagan Akun, periode sudah ditutup).
     */
    public function approveByDireksi(FinanceTransactionApprovalRequest $request, User $approver): FinanceTransaction
    {
        if (! $approver->isFullAccess()) {
            throw new RuntimeException('Cuma Direksi/Super Admin yang boleh menyetujui tahap ini.');
        }

        return DB::transaction(function () use ($request, $approver) {
            $locked = FinanceTransactionApprovalRequest::whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isPendingDireksi()) {
                throw new RuntimeException('Pengajuan ini bukan lagi menunggu persetujuan Direksi.');
            }

            $data = $locked->payload;

            // Payload divalidasi ULANG saat disetujui: kategori bisa saja
            // sudah nonaktif/dijadikan grup, atau toko dinonaktifkan sejak
            // pengajuan dibuat.
            $this->assertPayloadStillValid($data);

            $data['created_by'] = $locked->requested_by;

            $transaction = FinanceTransaction::create($data);
            // Pelaku jurnal = direksi yang menyetujui, bukan pengaju.
            $entry = app(FinanceTransactionPostingService::class)->post($transaction, $approver->id);
            $transaction->update(['journal_entry_id' => $entry->id]);

            $locked->update([
                'status' => 'approved',
                'direksi_approved_by' => $approver->id,
                'direksi_approved_at' => now(),
                'finance_transaction_id' => $transaction->id,
            ]);

            // Pengaju diberi tahu saat DISETUJUI (sebelumnya cuma saat ditolak).
            if ($requester = User::find($locked->requested_by)) {
                Notification::make()
                    ->title('Pengeluaran disetujui')
                    ->body('Nominal Rp' . number_format((float) ($data['amount'] ?? 0), 0, ',', '.') . ' sudah dicatat & diposting.')
                    ->success()
                    ->sendToDatabase($requester);

                app(\App\Services\PushNotificationService::class)->sendToUsers(
                    [$requester->id],
                    'Pengeluaran Disetujui',
                    'Pengeluaran Rp' . number_format((float) ($data['amount'] ?? 0), 0, ',', '.') . ' sudah disetujui dan dicatat (' . $transaction->transaction_number . ').'
                );
            }

            return $transaction;
        });
    }

    /**
     * @throws RuntimeException kalau pengajuan sudah final (approved/rejected).
     */
    public function reject(FinanceTransactionApprovalRequest $request, User $approver, ?string $note): void
    {
        $locked = DB::transaction(function () use ($request, $approver, $note) {
            $locked = FinanceTransactionApprovalRequest::whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || ! in_array($locked->status, ['pending_manager', 'pending_direksi'], true)) {
                throw new RuntimeException('Pengajuan ini sudah final, tidak bisa ditolak lagi.');
            }

            $locked->update([
                'status' => 'rejected',
                'rejected_by' => $approver->id,
                'rejected_at' => now(),
                'rejection_note' => $note,
            ]);

            return $locked;
        });

        // File nota dari pengajuan yang ditolak tidak lagi dirujuk siapa pun
        // (transaksinya tidak pernah dibuat) -- dihapus supaya tidak yatim.
        if ($receipt = $locked->payload['receipt'] ?? null) {
            \Illuminate\Support\Facades\Storage::disk(config('filament.default_filesystem_disk', 'public'))->delete($receipt);
        }

        if ($requester = User::find($locked->requested_by)) {
            Notification::make()
                ->title('Pengajuan pengeluaran ditolak')
                ->body('Nominal Rp' . number_format((float) ($locked->payload['amount'] ?? 0), 0, ',', '.') . ($note ? " — {$note}" : ''))
                ->danger()
                ->sendToDatabase($requester);

            app(\App\Services\PushNotificationService::class)->sendToUsers(
                [$requester->id],
                'Pengajuan Pengeluaran Ditolak',
                'Pengajuan Rp' . number_format((float) ($locked->payload['amount'] ?? 0), 0, ',', '.') . ' ditolak' . ($note ? ": {$note}" : '.')
            );
        }
    }

    /**
     * Pengaju membatalkan pengajuannya sendiri selama masih menunggu
     * (gap audit Transaksi Keuangan 2026-09-28). Lock + recheck status,
     * file nota dihapus karena transaksinya tidak akan pernah dibuat.
     *
     * @throws RuntimeException kalau bukan pengaju, atau pengajuan sudah diputuskan.
     */
    public function cancel(FinanceTransactionApprovalRequest $request, User $actor): void
    {
        $locked = DB::transaction(function () use ($request, $actor) {
            $locked = FinanceTransactionApprovalRequest::whereKey($request->id)->lockForUpdate()->first();

            if (! $locked || (int) $locked->requested_by !== (int) $actor->id) {
                throw new RuntimeException('Cuma pengaju yang boleh membatalkan pengajuan ini.');
            }

            if (! in_array($locked->status, ['pending_manager', 'pending_direksi'], true)) {
                throw new RuntimeException('Pengajuan ini sudah diputuskan, tidak bisa dibatalkan.');
            }

            $locked->update(['status' => 'cancelled']);

            return $locked;
        });

        if ($receipt = $locked->payload['receipt'] ?? null) {
            \Illuminate\Support\Facades\Storage::disk(config('filament.default_filesystem_disk', 'public'))->delete($receipt);
        }
    }

    /**
     * Ajukan ulang pengajuan yang DITOLAK/DIBATALKAN dengan data yang sama
     * (validasi kategori/toko dijalankan lagi lewat submit()). Nota tidak
     * ikut dibawa -- filenya sudah dihapus saat ditolak/dibatalkan.
     *
     * @throws RuntimeException
     */
    public function resubmit(FinanceTransactionApprovalRequest $request, User $actor): FinanceTransactionApprovalRequest
    {
        if ((int) $request->requested_by !== (int) $actor->id) {
            throw new RuntimeException('Cuma pengaju asli yang boleh mengajukan ulang.');
        }

        if (! in_array($request->status, ['rejected', 'cancelled'], true)) {
            throw new RuntimeException('Cuma pengajuan yang ditolak/dibatalkan yang bisa diajukan ulang.');
        }

        $payload = $request->payload;
        unset($payload['receipt']);

        return $this->submit($payload, $actor);
    }

    /**
     * @throws RuntimeException
     */
    private function assertPayloadStillValid(array $data): void
    {
        $category = \App\Models\FinanceCategory::find($data['finance_category_id'] ?? null);

        if (! $category || ! $category->is_active || $category->is_group) {
            throw new RuntimeException('Kategori pada pengajuan ini sudah tidak aktif atau tidak valid lagi. Tolak pengajuan dan minta pengaju membuat ulang.');
        }

        if (! $category->chart_of_account_id) {
            throw new RuntimeException("Kategori \"{$category->name}\" belum terhubung ke akun Bagan Akun.");
        }

        $store = \App\Models\Store::find($data['store_id'] ?? null);
        if (! $store || ! $store->is_active) {
            throw new RuntimeException('Toko pada pengajuan ini tidak ditemukan atau sudah nonaktif.');
        }
    }

    private function notifyNextApprovers(FinanceTransactionApprovalRequest $request): void
    {
        $amountLabel = 'Rp' . number_format((float) ($request->payload['amount'] ?? 0), 0, ',', '.');

        if ($request->isPendingManager()) {
            $storeId = $request->store_id_from_payload;
            $recipients = User::where('store_id', $storeId)->where('is_active', true)->get()->filter(fn (User $u) => $u->isStoreManager());
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
