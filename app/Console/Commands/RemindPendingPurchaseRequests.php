<?php

namespace App\Console\Commands;

use App\Services\PurchaseRequestNotifier;
use Illuminate\Console\Command;

/**
 * Pengingat harian untuk penyetuju: Permohonan Pembelian yang menunggu persetujuan lebih dari 1 hari (audit Permohonan Pembelian, 2026-11-02).
 */
class RemindPendingPurchaseRequests extends Command
{
    protected $signature = 'purchase-requests:remind-pending';

    protected $description = 'Ingatkan penyetuju untuk permohonan pembelian yang menunggu lebih dari 1 hari';

    public function handle(PurchaseRequestNotifier $notifier): int
    {
        $count = $notifier->remindPending();

        $this->info($count > 0 ? "{$count} permohonan diingatkan." : 'Tidak ada permohonan yang perlu diingatkan.');

        return self::SUCCESS;
    }
}
