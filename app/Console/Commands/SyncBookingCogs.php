<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\BookingCogsPostingService;
use Illuminate\Console\Command;

/**
 * Menyinkronkan jurnal HPP booking yang sudah selesai (Keuangan Fase 2, 2026-11-06): memakai pemakaian bahan yang baru tercatat setelah jurnal
 * pendapatan dibuat dan mengulang yang gagal. Hanya berjalan bila fitur persediaan di Buku Besar menyala (FEATURE_INVENTORY_GL).
 */
class SyncBookingCogs extends Command
{
    protected $signature = 'cogs:sync {--days=45 : Booking selesai yang diubah dalam N hari terakhir} {--booking= : Hanya booking dengan id ini}';

    protected $description = 'Sinkronkan jurnal HPP booking selesai dengan pemakaian bahan terbaru';

    public function handle(BookingCogsPostingService $service): int
    {
        if (! BookingCogsPostingService::enabled()) {
            $this->info('Fitur HPP/persediaan di Buku Besar belum menyala (FEATURE_INVENTORY_GL); tidak ada yang dikerjakan.');

            return self::SUCCESS;
        }

        $query = Booking::query()->where('status', 'completed')->whereNotNull('journal_entry_id');

        if ($id = $this->option('booking')) {
            $query->whereKey((int) $id);
        } else {
            $query->where('updated_at', '>=', now()->subDays(max(1, (int) $this->option('days'))));
        }

        $done = 0;
        $failed = 0;

        $query->orderBy('id')->each(function (Booking $booking) use ($service, &$done, &$failed) {
            try {
                $service->sync($booking);
                $done++;
            } catch (\Throwable $e) {
                $failed++;
                report($e);
                $this->warn("Booking {$booking->booking_number}: {$e->getMessage()}");
            }
        });

        $this->info("Selesai: {$done} booking disinkronkan, {$failed} gagal.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
