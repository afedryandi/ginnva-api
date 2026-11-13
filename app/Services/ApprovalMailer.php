<?php

namespace App\Services;

use App\Filament\Pages\ApprovalCenter;
use App\Mail\ApprovalRequestedMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Email pengajuan baru ke direksi (2026-11-13). Hanya akses penuh (direksi, super admin) yang diberi email: store manager sudah dapat push di
 * aplikasi. Kegagalan kirim tidak boleh menggagalkan pengajuannya, dan setiap penerima diberi tahu paling banyak satu email per pengajuan.
 * Dimatikan dengan APPROVAL_EMAIL=false di .env.
 */
class ApprovalMailer
{
    /**
     * @param  iterable<int|string>  $recipientIds  id pengguna yang berhak memutuskan pengajuan ini
     */
    public function requested(string $typeLabel, string $who, string $summary, ?string $storeName, iterable $recipientIds): void
    {
        if (! config('approvals.email')) {
            return;
        }

        try {
            $users = User::query()
                ->whereIn('id', collect($recipientIds)->filter()->unique()->all())
                ->where('is_active', true)
                ->whereNotNull('email')
                ->get()
                ->filter(fn (User $u) => $u->isFullAccess() && filter_var($u->email, FILTER_VALIDATE_EMAIL));

            if ($users->isEmpty()) {
                return;
            }

            $url = ApprovalCenter::getUrl(panel: 'admin');
            $center = app(ApprovalCenterService::class);

            foreach ($users as $user) {
                try {
                    // Jumlah dihitung baru (tanpa cache) supaya angka di email sudah termasuk pengajuan yang baru masuk.
                    $center->forgetBadge($user);
                    $count = $center->badgeCount($user);

                    Mail::to($user->email)->send(new ApprovalRequestedMail($typeLabel, $who, $summary, $storeName, $url, max(1, $count)));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
