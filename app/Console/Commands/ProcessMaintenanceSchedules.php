<?php

namespace App\Console\Commands;

use App\Models\WarrantyMaintenanceSchedule;
use App\Services\PushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bagian C, "Klaim Garansi & Maintenance PPF Terhubung ke Slot Booking"
 * (2026-10-01) -- jalan harian (lihat routes/console.php), 2 tanggung jawab:
 *
 * 1. Kirim push "Konfirmasi Kedatangan" untuk occurrence yang scheduled_date
 *    sudah dekat (H-7) dan belum pernah dikirim ('pending' -> 'confirmation_sent').
 * 2. Hanguskan (forfeit) occurrence yang scheduled_date-nya sudah LEWAT tapi
 *    customer tidak pernah merespons -- otomatis siapkan occurrence
 *    berikutnya selama kuota belum habis (lihat WarrantyMaintenanceSchedule::forfeit()).
 */
class ProcessMaintenanceSchedules extends Command
{
    protected $signature = 'maintenance:process-schedules';

    protected $description = 'Kirim konfirmasi kedatangan maintenance PPF yang mendekati jadwal, dan hanguskan yang lewat tanggal tanpa respons';

    private const REMINDER_WINDOW_DAYS = 7;

    public function handle(PushNotificationService $push): int
    {
        $this->sendConfirmations($push);
        $this->forfeitOverdue();

        return self::SUCCESS;
    }

    private function sendConfirmations(PushNotificationService $push): void
    {
        $due = WarrantyMaintenanceSchedule::where('status', 'pending')
            ->whereDate('scheduled_date', '<=', today()->addDays(self::REMINDER_WINDOW_DAYS))
            ->with('warranty')
            ->get()
            ->filter(fn (WarrantyMaintenanceSchedule $s) => $s->warranty?->customer_id !== null);

        foreach ($due as $schedule) {
            DB::transaction(function () use ($schedule, $push) {
                $locked = WarrantyMaintenanceSchedule::whereKey($schedule->id)->lockForUpdate()->first();

                // Sudah diproses run sebelumnya / sudah dikonfirmasi manual
                // di antara query & lock ini -- skip, jangan kirim dobel.
                if (! $locked || $locked->status !== 'pending') {
                    return;
                }

                $warranty = $schedule->warranty;

                $push->sendToCustomer(
                    $warranty->customer_id,
                    'Konfirmasi Kedatangan Maintenance PPF',
                    "Waktunya maintenance PPF untuk {$warranty->warranty_code} (jadwal: {$locked->scheduled_date->format('d M Y')}). Konfirmasi kedatangan Anda sekarang.",
                    [
                        'type'        => 'ppf_maintenance_confirm',
                        'schedule_id' => $locked->id,
                        'warranty_id' => $warranty->id,
                        // Bug diperbaiki 2026-10-01 (audit Bagian C) --
                        // SEBELUMNYA mengarah ke daftar (/account/my-warranties),
                        // customer harus cari manual. Langsung ke detail yang
                        // punya kartu Konfirmasi/Tolak, sama pola dengan
                        // banner beranda ((tabs)/index.tsx) dan push lain
                        // (mis. booking_confirmed -> langsung ke chat booking).
                        'route'       => "/account/warranty-detail?id={$warranty->id}",
                    ]
                );

                $locked->update(['status' => 'confirmation_sent', 'reminder_sent_at' => now()]);
            });
        }

        $this->info("Konfirmasi dikirim: {$due->count()}.");
    }

    private function forfeitOverdue(): void
    {
        $overdue = WarrantyMaintenanceSchedule::where('status', 'confirmation_sent')
            ->whereDate('scheduled_date', '<', today())
            ->get();

        foreach ($overdue as $schedule) {
            $schedule->forfeit(explicit: false);
        }

        $this->info("Occurrence hangus (lewat tanggal tanpa respons): {$overdue->count()}.");
    }
}
