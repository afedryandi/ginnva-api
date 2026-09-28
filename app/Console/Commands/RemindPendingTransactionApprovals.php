<?php

namespace App\Console\Commands;

use App\Filament\Resources\TransactionApprovalRequestResource;
use App\Models\TransactionApprovalRequest;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Reminder pengajuan Persetujuan Transaksi (Referral/Refund/DP) yang
 * menunggu lebih dari 2 hari (audit Persetujuan Transaksi 2026-09-29, setara
 * RemindPendingExpenseApprovals): 1 ringkasan per full-access per hari, bukan
 * 1 notifikasi per pengajuan. Idempotent alami -- hanya membaca status;
 * pengajuan yang sudah diputuskan/dibatalkan otomatis keluar. Pengaju tidak
 * diingatkan tentang pengajuannya sendiri.
 */
class RemindPendingTransactionApprovals extends Command
{
    protected $signature = 'transactions:remind-pending-approvals';

    protected $description = 'Ingatkan direksi soal pengajuan Referral/Refund/DP yang menunggu lebih dari 2 hari';

    public function handle(): int
    {
        $stale = TransactionApprovalRequest::query()
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subDays(2))
            ->get();

        if ($stale->isEmpty()) {
            $this->info('Tidak ada pengajuan yang menggantung.');

            return self::SUCCESS;
        }

        $pushed = [];

        foreach (User::where('is_active', true)->get()->filter(fn (User $u) => $u->isFullAccess()) as $user) {
            $mine = $stale->where('requested_by', '!=', $user->id);

            if ($mine->isEmpty()) {
                continue;
            }

            Notification::make()
                ->title($mine->count() . ' pengajuan transaksi menunggu keputusan Anda > 2 hari')
                ->body('Referral, Refund, atau DP dari staff belum diputuskan. Segera proses di menu Persetujuan Transaksi.')
                ->warning()
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('Lihat')
                        ->url(TransactionApprovalRequestResource::getUrl('index'))
                        ->markAsRead(),
                ])
                ->sendToDatabase($user);

            $pushed[] = $user->id;
        }

        try {
            app(PushNotificationService::class)->sendToUsers(
                $pushed,
                'Pengajuan Transaksi Menunggu',
                'Ada pengajuan Referral/Refund/DP yang menunggu keputusan Anda lebih dari 2 hari.'
            );
        } catch (\Throwable $e) {
            report($e);
        }

        $this->info('Reminder dikirim ke ' . count($pushed) . " approver ({$stale->count()} pengajuan menggantung).");

        return self::SUCCESS;
    }
}
