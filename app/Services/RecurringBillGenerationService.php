<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\Payable;
use App\Models\RecurringBillTemplate;
use App\Models\Supplier;
use App\Models\User;
use Filament\Notifications\Notification;
use RuntimeException;
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

    /** @var array<int, array{template: RecurringBillTemplate, error: string}> template yang gagal pada runDue() terakhir */
    private array $failures = [];

    /** @return array<int, array{template: RecurringBillTemplate, error: string}> */
    public function getFailures(): array
    {
        return $this->failures;
    }

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

                    $this->failures[] = ['template' => $template, 'error' => $e->getMessage()];
                    $this->recordFailure($template, $e);
                }
            });

        $this->notifySuccessSummary($generated);

        return $generated;
    }

    /** Ringkasan harian ke direksi kalau ada tagihan yang di-generate (1 notifikasi per direksi). */
    private function notifySuccessSummary(Collection $generated): void
    {
        if ($generated->isEmpty()) {
            return;
        }

        try {
            $total = $generated->sum(fn (Payable $p) => (float) $p->amount);
            $body = $generated->count() . ' tagihan rutin dibuat otomatis, total Rp ' . number_format($total, 0, ',', '.') . '.';

            foreach (User::where('is_active', true)->get()->filter(fn (User $u) => $u->isFullAccess()) as $user) {
                Notification::make()
                    ->title('Tagihan rutin dibuat otomatis')
                    ->body($body)
                    ->success()
                    ->actions([
                        \Filament\Notifications\Actions\Action::make('view')
                            ->label('Lihat Hutang Usaha')
                            ->url(\App\Filament\Resources\PayableResource::getUrl('index'))
                            ->markAsRead(),
                    ])
                    ->sendToDatabase($user);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * "Jalankan sekarang": generate jadwal berikutnya template ini tanpa menunggu cron
     * (idempoten lewat source_key; jeda diabaikan karena ini permintaan eksplisit).
     *
     * @throws RuntimeException kalau template nonaktif/sudah selesai, atau generate gagal.
     */
    public function runNow(RecurringBillTemplate $template): ?Payable
    {
        $result = $this->runOnce($template->id, $template->next_run_date->copy(), true);

        if ($result === false) {
            throw new RuntimeException('Template nonaktif atau sudah selesai (melewati tanggal berakhir / batas jumlah tagihan).');
        }

        return $result;
    }

    /**
     * Satu bulan untuk satu template, atomik.
     *
     * @return Payable|null|false Payable baru; null kalau bulan itu sudah pernah
     *                            dibuat (hanya majukan tanggal); false kalau tidak ada yang jatuh tempo.
     */
    private function runOnce(int $templateId, Carbon $today, bool $force = false): Payable|null|false
    {
        return DB::transaction(function () use ($templateId, $today, $force) {
            $template = RecurringBillTemplate::whereKey($templateId)->lockForUpdate()->first();

            if (! $template || ! $template->is_active || $template->next_run_date->gt($today)) {
                return false;
            }

            // Masa berlaku habis / batas jumlah tagihan tercapai -> template dinonaktifkan otomatis.
            if ($template->isFinishedFor($template->next_run_date)) {
                $template->update(['is_active' => false]);
                $this->notifyFinished($template);

                return false;
            }

            $runDate = $template->next_run_date->toDateString();

            // Jeda terjadwal: bulan di dalam masa jeda dilewati (jadwal maju, tanpa tagihan).
            if (! $force && $template->paused_until && $template->next_run_date->lte($template->paused_until)) {
                $template->update(['next_run_date' => $template->computeNextRunDate()]);

                return null;
            }

            $key = PayableService::sourceKey('recurring_bill_template', $template->id, $runDate);

            $payable = null;
            if (! Payable::withoutGlobalScopes()->where('source_key', $key)->exists()) {
                $payable = $this->generateOne($template, $runDate, $key);
            }

            $template->update(['next_run_date' => $template->computeNextRunDate()]);

            // Status eksekusi lewat query builder (tanpa event/log aktivitas): sukses = error dikosongkan.
            DB::table('recurring_bill_templates')->where('id', $template->id)->update([
                'last_run_at' => now(),
                'last_error' => null,
                'last_error_at' => null,
            ]);

            return $payable;
        });
    }

    /**
     * Simpan pesan error terakhir di template (terlihat di UI) dan beri tahu direksi.
     * Tidak boleh melempar -- kegagalan notifikasi tidak boleh menutupi kegagalan aslinya.
     */
    private function recordFailure(RecurringBillTemplate $template, \Throwable $e): void
    {
        try {
            DB::table('recurring_bill_templates')->where('id', $template->id)->update([
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'last_error_at' => now(),
            ]);

            foreach (User::where('is_active', true)->get()->filter(fn (User $u) => $u->isFullAccess()) as $user) {
                Notification::make()
                    ->title("Tagihan rutin \"{$template->name}\" gagal dibuat")
                    ->body(mb_substr($e->getMessage(), 0, 300) . ' — perbaiki template/akun/periodenya; generate diulang otomatis besok.')
                    ->danger()
                    ->actions([
                        \Filament\Notifications\Actions\Action::make('view')
                            ->label('Lihat Template')
                            ->url(\App\Filament\Resources\RecurringBillTemplateResource::getUrl('edit', ['record' => $template->id]))
                            ->markAsRead(),
                    ])
                    ->sendToDatabase($user);
            }
        } catch (\Throwable $inner) {
            report($inner);
        }
    }

    private function notifyFinished(RecurringBillTemplate $template): void
    {
        try {
            foreach (User::where('is_active', true)->get()->filter(fn (User $u) => $u->isFullAccess()) as $user) {
                Notification::make()
                    ->title("Template tagihan rutin \"{$template->name}\" selesai")
                    ->body('Masa berlaku/batas jumlah tagihan tercapai, template dinonaktifkan otomatis.')
                    ->info()
                    ->sendToDatabase($user);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Akun tujuan harus akun beban yang masih aktif & postable (bisa berubah sejak template dibuat). */
    private function assertAccountUsable(RecurringBillTemplate $template): void
    {
        $account = ChartOfAccount::find($template->chart_of_account_id);

        if (! $account || ! $account->is_active || ! $account->is_postable || ! in_array($account->type, RecurringBillTemplate::EXPENSE_TYPES, true)) {
            throw new RuntimeException('Akun beban tujuan template tidak valid lagi (dihapus, nonaktif, header, atau bukan akun beban) — pilih akun lain di template.');
        }
    }

    /** Cari supplier master (abaikan spasi/kapitalisasi) atau buat baru, supaya tidak menggandakan master. */
    private function resolveSupplier(string $name): Supplier
    {
        $clean = trim(preg_replace('/\s+/', ' ', $name));

        return Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($clean)])->first()
            ?? Supplier::create(['name' => $clean]);
    }

    private function generateOne(RecurringBillTemplate $template, string $runDate, string $key): Payable
    {
        $this->assertAccountUsable($template);

        // Jurnal bertanggal bulan tagihan (beban jatuh di periode yang benar);
        // kalau periodenya sudah ditutup, dicatat di tanggal hari ini.
        $entryDate = AccountingPeriod::isClosedFor(Carbon::parse($runDate)) || $runDate > now()->toDateString()
            ? now()->toDateString()
            : $runDate;

        return app(PayableService::class)->createWithJournal([
            'supplier_name' => $template->supplier_name,
            'supplier_id' => $template->supplier_id ?? $this->resolveSupplier($template->supplier_name)->id,
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
