<?php

namespace App\Console\Commands;

use App\Filament\Resources\FinanceTransactionApprovalRequestResource;
use App\Models\FinanceTransactionApprovalRequest;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Reminder pengajuan pengeluaran yang menggantung (audit Transaksi Keuangan
 * 2026-09-28): pengajuan yang menunggu lebih dari 2 hari tanpa keputusan
 * diingatkan ke approver tahap itu (store manager toko terkait untuk
 * pending_manager, full-access untuk pending_direksi) -- 1 ringkasan per
 * penerima per hari (bukan 1 notifikasi per pengajuan). Idempotent alami:
 * hanya membaca status, tidak menyimpan state; pengajuan yang sudah
 * diputuskan/dibatalkan otomatis keluar dari kandidat.
 */
class RemindPendingExpenseApprovals extends Command
{
    protected $signature = 'finance:remind-pending-approvals';

    protected $description = 'Ingatkan approver soal pengajuan pengeluaran yang menunggu lebih dari 2 hari';

    public function handle(): int
    {
        $stale = FinanceTransactionApprovalRequest::query()
            ->whereIn('status', ['pending_manager', 'pending_direksi'])
            ->where('created_at', '<=', now()->subDays(2))
            ->get();

        if ($stale->isEmpty()) {
            $this->info('Tidak ada pengajuan yang menggantung.');

            return self::SUCCESS;
        }

        $users = User::where('is_active', true)->get();
        $pushed = [];

        foreach ($users as $user) {
            $mine = $stale->filter(function (FinanceTransactionApprovalRequest $r) use ($user) {
                if ($r->isPendingDireksi()) {
                    return $user->isFullAccess();
                }

                return $user->isFullAccess()
                    || ($user->isStoreManager() && (int) $user->store_id === (int) $r->store_id_from_payload);
            });

            if ($mine->isEmpty()) {
                continue;
            }

            $total = $mine->sum(fn (FinanceTransactionApprovalRequest $r) => (float) ($r->payload['amount'] ?? 0));

            Notification::make()
                ->title($mine->count() . ' pengajuan pengeluaran menunggu keputusan Anda > 2 hari')
                ->body('Total Rp' . number_format($total, 0, ',', '.') . '. Segera proses di menu Persetujuan Pengeluaran.')
                ->warning()
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('Lihat')
                        ->url(FinanceTransactionApprovalRequestResource::getUrl('index'))
                        ->markAsRead(),
                ])
                ->sendToDatabase($user);

            $pushed[] = $user->id;
        }

        app(PushNotificationService::class)->sendToUsers(
            $pushed,
            'Pengajuan Pengeluaran Menunggu',
            'Ada pengajuan pengeluaran yang menunggu keputusan Anda lebih dari 2 hari.'
        );

        $this->info("Reminder dikirim ke " . count($pushed) . " approver ({$stale->count()} pengajuan menggantung).");

        return self::SUCCESS;
    }
}
