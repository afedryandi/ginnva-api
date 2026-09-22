<?php

namespace App\Services;

use App\Models\Payable;
use App\Models\RecurringBillTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Template tagihan rutin yg auto-generate Biaya/AP" (audit Majoo,
 * f48), dibangun 2026-09-22 atas keputusan user: begitu template
 * dibuat/dikonfirmasi full-access, generate bulanan berikutnya
 * LANGSUNG terposting otomatis (tidak perlu approval ulang tiap bulan)
 * -- lewat PayableService::createWithJournal() yang sudah ada, sama
 * jalur dengan entry manual biasa.
 */
class RecurringBillGenerationService
{
    /**
     * Dipanggil dari command terjadwal harian. Generate SEMUA template
     * aktif yang next_run_date-nya sudah lewat/hari ini -- kalau
     * command sempat tidak jalan beberapa hari (server down dll),
     * loop while() di bawah "mengejar" semua bulan yang terlewat satu
     * per satu (bukan cuma 1x lompat ke hari ini), supaya tidak ada
     * bulan yang hilang dari Hutang Usaha.
     *
     * @return Collection<int, Payable>
     */
    public function runDue(?Carbon $today = null): Collection
    {
        $today = $today ?? now();
        $generated = collect();

        RecurringBillTemplate::where('is_active', true)
            ->where('next_run_date', '<=', $today->toDateString())
            ->each(function (RecurringBillTemplate $template) use ($today, &$generated) {
                while ($template->is_active && $template->next_run_date->lte($today)) {
                    $generated->push($this->generateOne($template));

                    $template->update(['next_run_date' => $template->computeNextRunDate()]);
                    $template->refresh();
                }
            });

        return $generated;
    }

    private function generateOne(RecurringBillTemplate $template): Payable
    {
        return app(PayableService::class)->createWithJournal([
            'supplier_name' => $template->supplier_name,
            'store_id' => $template->store_id,
            'amount' => (float) $template->amount,
            'due_date' => $template->next_run_date->toDateString(),
            'source_type' => 'recurring_bill_template',
            'source_id' => $template->id,
            'notes' => trim(($template->name) . ($template->notes ? " — {$template->notes}" : '') . ' (auto-generate)'),
            'created_by' => $template->created_by,
        ], (int) $template->chart_of_account_id);
    }
}
