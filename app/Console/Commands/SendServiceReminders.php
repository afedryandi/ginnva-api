<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\ServiceReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reminder servis berkala — jalan harian (lihat routes/console.php),
 * kirim ke booking yang tanggal `next_service_reminder_at`-nya = hari ini
 * dan belum pernah terkirim. Tanggalnya ditentukan MANUAL oleh store
 * manager per booking lewat Filament (BookingResource), bukan dihitung
 * otomatis oleh sistem — command ini cuma eksekutor pengiriman.
 *
 * Logic pengiriman per-channel ada di ServiceReminderService (dipakai
 * bareng dengan trigger manual "Kirim Pengingat Maintenance").
 */
class SendServiceReminders extends Command
{
    protected $signature = 'reminders:send-service';

    protected $description = 'Kirim reminder servis berkala (WhatsApp+Push+Email) untuk booking yang jatuh tempo hari ini';

    public function handle(ServiceReminderService $reminders): int
    {
        $bookings = Booking::whereDate('next_service_reminder_at', today())
            ->whereNull('service_reminder_sent_at')
            ->with(['customer', 'store'])
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('Tidak ada reminder servis yang jatuh tempo hari ini.');

            return self::SUCCESS;
        }

        // Antara query di atas dan loop ini, staff bisa saja sudah kirim
        // manual lewat "Kirim Pengingat Maintenance" (Filament/mobile) untuk
        // salah satu booking yang sama — tanpa re-cek, command ini tetap
        // kirim ulang (duplikat WA/push/email dalam hitungan detik). Lock +
        // re-cek service_reminder_sent_at di dalam transaction memastikan
        // command SKIP booking yang barusan dikirim manual.
        foreach ($bookings as $booking) {
            DB::transaction(function () use ($booking, $reminders) {
                $locked = Booking::where('id', $booking->id)->lockForUpdate()->first();
                if (! $locked || $locked->service_reminder_sent_at !== null) return;

                $reminders->sendFor($locked->setRelations($booking->getRelations()));
            });
        }

        $this->info("Selesai — {$bookings->count()} reminder diproses.");

        return self::SUCCESS;
    }
}
