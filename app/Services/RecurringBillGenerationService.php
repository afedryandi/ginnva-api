<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\Payable;
use App\Models\RecurringBillTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Template tagihan rutin yg auto-generate Biaya/AP" (audit Majoo,
 * f48), dibangun 2026-09-22 atas keputusan user: begitu template
 * dibuat/dikonfirmasi full-access, generate bulanan berikutnya
 * LANGSUNG terposting otomatis (tidak perlu approval ulang tiap bulan)
 * -- lewat PayableService::createWithJournal() yang sudah ada, sama
 * jalur dengan entry manual biasa.
 *
 * Audit Hutang Usaha 2026-09-29: tiap bulan diproses dalam 1 transaksi yang
 * mengunci template (generate + majukan next_run_date atomik), idempotent lewat
 * source_key (crash di tengah tidak bikin tagihan ganda), 1 template gagal tidak
 * menghentikan template lain, tanggal jurnal = tanggal jatuh tempo bulan itu.
 */
class RecurringBillGenerationService
{
    private const MAX_CATCH_UP = 60;

    /**
     * Dipanggil dari command terjadwal harian. Generate SEMUA template
     * aktif yang next_run_date-nya sudah lewat/hari ini -- kalau
     * command sempat tidak jalan beberapa hari (server down dll),
     * loop di bawah "mengejar" semua bulan yang terlewat satu
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
            ->get()
            ->each(function (RecurringBillTemplate $template) use ($today, $generated) {
                try {
                    for ($i = 0; $i < self::MAX_CATCH_UP; $i++) {
                        $payable = $this->runOnce($template->id, $today);

                        if ($payable === false) {
                            break; // tidak ada lagi yang jatuh tempo
                        }

                        if ($payable) {
                            $generated->push($payable);
                        }
                    }
                } catch (\Throwable $e) {
                    // Satu template bermasalah (periode tutup, akun nonaktif, dll)
                    // tidak boleh menghentikan template lain.
                    report($e);
                    Log::error("Tagihan rutin template #{$template->id} gagal: {$e->getMessage()}");
                }
            });

        return $generated;
    }

    /**
     * Satu bulan untuk satu template, atomik.
     *
     * @return Payable|null|false Payable baru; null kalau bulan itu sudah pernah
     *                            dibuat (hanya majukan tanggal); false kalau tidak ada yang jatuh tempo.
     */
    private function runOnce(int $templateId, Carbon $today): Payable|null|false
    {
        return DB::transaction(function () use ($templateId, $today) {
            $template = RecurringBillTemplate::whereKey($templateId)->lockForUpdate()->first();

            if (! $template || ! $template->is_active || $template->next_run_date->gt($today)) {
                return false;
            }

            $runDate = $template->next_run_date->toDateString();
            $key = PayableService::sourceKey('recurring_bill_template', $template->id, $runDate);

            $payable = null;
            if (! Payable::withoutGlobalScopes()->where('source_key', $key)->exists()) {
                $payable = $this->generateOne($template, $runDate, $key);
            }

            $template->update(['next_run_date' => $template->computeNextRunDate()]);

            return $payable;
        });
    }

    private function generateOne(RecurringBillTemplate $template, string $runDate, string $key): Payable
    {
        // Jurnal bertanggal bulan tagihan (beban jatuh di periode yang benar);
        // kalau periodenya sudah ditutup, dicatat di tanggal hari ini.
        $entryDate = AccountingPeriod::isClosedFor(Carbon::parse($runDate)) || $runDate > now()->toDateString()
            ? now()->toDateString()
            : $runDate;

        return app(PayableService::class)->createWithJournal([
            'supplier_name' => $template->supplier_name,
            'supplier_id' => \App\Models\Supplier::firstOrCreate(['name' => $template->supplier_name])->id,
            'store_id' => $template->store_id,
            'amount' => (float) $template->amount,
            'due_date' => $runDate,
            'entry_date' => $entryDate,
            'source_type' => 'recurring_bill_template',
            'source_id' => $template->id,
            'source_key' => $key,
            'notes' => trim(($template->name) . ($template->notes ? " — {$template->notes}" : '') . ' (auto-generate)'),
            'created_by' => $template->created_by,
        ], (int) $template->chart_of_account_id);
    }
}
