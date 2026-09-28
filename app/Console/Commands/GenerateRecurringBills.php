<?php

namespace App\Console\Commands;

use App\Services\RecurringBillGenerationService;
use Illuminate\Console\Command;

/**
 * "Template tagihan rutin yg auto-generate Biaya/AP" (audit Majoo, f48).
 * Dijadwalkan harian (lihat routes/console.php) -- generate SEMUA
 * template aktif yang jatuh tempo, langsung posting (keputusan user
 * 2026-09-22: tidak perlu approval ulang tiap bulan).
 *
 * Audit Template Tagihan Rutin 2026-09-29: template yang gagal dicantumkan di output dan
 * command keluar FAILURE (terlihat di monitoring), bukan diam-diam "0 di-generate".
 */
class GenerateRecurringBills extends Command
{
    protected $signature = 'billing:generate-recurring';

    protected $description = 'Generate Hutang Usaha (Payable) dari template tagihan rutin yang sudah jatuh tempo';

    public function handle(RecurringBillGenerationService $service): int
    {
        $generated = $service->runDue();

        $this->info("{$generated->count()} tagihan rutin di-generate.");

        $failures = $service->getFailures();

        foreach ($failures as $failure) {
            $this->error("GAGAL template #{$failure['template']->id} \"{$failure['template']->name}\": {$failure['error']}");
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
