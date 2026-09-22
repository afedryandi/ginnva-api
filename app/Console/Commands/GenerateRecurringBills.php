<?php

namespace App\Console\Commands;

use App\Services\RecurringBillGenerationService;
use Illuminate\Console\Command;

/**
 * "Template tagihan rutin yg auto-generate Biaya/AP" (audit Majoo, f48).
 * Dijadwalkan harian (lihat routes/console.php) -- generate SEMUA
 * template aktif yang jatuh tempo, langsung posting (keputusan user
 * 2026-09-22: tidak perlu approval ulang tiap bulan).
 */
class GenerateRecurringBills extends Command
{
    protected $signature = 'billing:generate-recurring';

    protected $description = 'Generate Hutang Usaha (Payable) dari template tagihan rutin yang sudah jatuh tempo';

    public function handle(RecurringBillGenerationService $service): int
    {
        $generated = $service->runDue();

        $this->info("{$generated->count()} tagihan rutin di-generate.");

        return self::SUCCESS;
    }
}
