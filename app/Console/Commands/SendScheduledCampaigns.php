<?php

namespace App\Console\Commands;

use App\Models\NotificationCampaign;
use App\Models\User;
use App\Services\NotificationCampaignService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Notifikasi Fase 5 (2026-10-21): kirim kampanye terjadwal yang sudah jatuh tempo. Tiap kampanye "diklaim" dengan pembaruan
 * bersyarat sebelum dikirim, sehingga dua proses yang berjalan bersamaan tidak mengirim kampanye yang sama dua kali.
 * Kampanye yang tertahan di status "sedang dikirim" lebih dari 30 menit (proses berhenti di tengah jalan) ditandai gagal.
 */
class SendScheduledCampaigns extends Command
{
    protected $signature = 'notifications:send-scheduled';

    protected $description = 'Kirim kampanye notifikasi terjadwal yang sudah jatuh tempo';

    public function handle(NotificationCampaignService $service): int
    {
        NotificationCampaign::where('status', NotificationCampaign::SENDING)
            ->where('updated_at', '<', now()->subMinutes(30))
            ->update(['status' => NotificationCampaign::FAILED, 'message' => 'Proses pengiriman berhenti di tengah jalan. Periksa Log Pengiriman sebelum mengirim ulang.']);

        $sent = 0;
        $failed = 0;

        $due = NotificationCampaign::where('status', NotificationCampaign::SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        foreach ($due as $campaign) {
            $claimed = NotificationCampaign::where('id', $campaign->id)
                ->where('status', NotificationCampaign::SCHEDULED)
                ->update(['status' => NotificationCampaign::SENDING]);

            if ($claimed !== 1) {
                continue;
            }

            // Pengirim tercatat sebagai pembuat kampanye (riwayat notifikasi pelanggan memakai auth()->id()).
            $creator = $campaign->created_by ? User::find($campaign->created_by) : null;
            $creator ? Auth::setUser($creator) : null;

            try {
                $outcome = $service->dispatch($campaign->refresh());
            } finally {
                $creator ? Auth::forgetUser() : null;
            }

            $outcome['ok'] ? $sent++ : $failed++;
        }

        $this->info("Kampanye terjadwal: {$sent} terkirim, {$failed} gagal.");

        return self::SUCCESS;
    }
}
