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
        // Hangus dulu: jadwal lewat tanggal tidak boleh lagi dikirimi konfirmasi.
        $this->forfeitOverdue();
        $this->sendConfirmations($push);

        return self::SUCCESS;
    }

    private function sendConfirmations(PushNotificationService $push): void
    {
        // Hanya jadwal yang BELUM melewati masa toleransi: yang lewat dihanguskan oleh forfeitOverdue()
        // (konfirmasi untuk jadwal yang sudah hangus ditolak server, jadi tidak boleh dikirimi push).
        $due = WarrantyMaintenanceSchedule::where('status', 'pending')
            ->whereDate('scheduled_date', '>=', today()->subDays(WarrantyMaintenanceSchedule::GRACE_DAYS))
            ->whereDate('scheduled_date', '<=', today()->addDays(self::REMINDER_WINDOW_DAYS))
            ->with('warranty.store')
            ->get()
            // Audit Maintenance PPF 2026-10-01 -- SEBELUMNYA tidak ada
            // pengecekan warranty revoked/toko non-aktif sama sekali di
            // sini, jadi push "Konfirmasi Kedatangan" tetap terkirim untuk
            // garansi yang sudah dibatalkan atau toko yang sudah
            // dinonaktifkan. Occurrence-nya TETAP dibiarkan di status
            // 'pending' (bukan diforfeit paksa) -- kalau warranty
            // di-reaktivasi atau toko diaktifkan lagi nanti, siklus bisa
            // lanjut natural tanpa kehilangan riwayat sequence.
            ->filter(fn (WarrantyMaintenanceSchedule $s) => $s->warranty?->customer_id !== null
                && $s->warranty->status !== 'revoked'
                && ($s->warranty->store?->is_active ?? true));

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
        // Eager-load warranty.store (audit 2026-10-01, N+1 diperbaiki) --
        // forfeit() akses $warranty->store lewat WarrantyMaintenanceSchedule::nextOpenDate()
        // (fitur "geser ke hari buka"), tanpa ini tiap baris yang hangus
        // memicu 2 query lazy-load tambahan (warranty + store).
        // 'pending' yang tanggalnya lewat (tidak pernah sempat dikonfirmasi) ikut
        // hangus -- kecuali garansi di-revoke / toko nonaktif, yang sengaja
        // dibiarkan pending supaya siklus bisa lanjut kalau diaktifkan lagi.
        $overdue = WarrantyMaintenanceSchedule::whereIn('status', ['pending', 'confirmation_sent'])
            // Masa toleransi 30 hari setelah tanggal jadwal (2026-10-09): baru hangus kalau lewat dari itu.
            ->whereDate('scheduled_date', '<', today()->subDays(WarrantyMaintenanceSchedule::GRACE_DAYS))
            ->with('warranty.store')
            ->get()
            ->filter(fn (WarrantyMaintenanceSchedule $s) => $s->status === 'confirmation_sent'
                || ($s->warranty && $s->warranty->status !== 'revoked' && ($s->warranty->store?->is_active ?? true)));

        foreach ($overdue as $schedule) {
            $schedule->forfeit(explicit: false);
        }

        $this->info("Occurrence hangus (lewat tanggal tanpa respons): {$overdue->count()}.");
    }
}
