<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingHandover;
use App\Models\BookingIntakePhoto;
use App\Models\BookingMessagePhoto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Retensi foto kendaraan (Fase 5 pengerjaan ulang booking, 2026-10-21; keputusan pemilik: 24 bulan, UU PDP/PP 33/2026).
 *
 * Menghapus FILE dan baris foto penerimaan, foto akhir serah terima, dan foto progres (chat) milik booking yang sudah
 * selesai/dibatalkan dan tanggal layanannya lebih dari config('booking.photo_retention_months') bulan lalu. Data teks booking
 * tetap utuh. Tanggal layanan (preferred_date) dipakai sebagai patokan "sejak selesai".
 */
class PruneBookingPhotos extends Command
{
    protected $signature = 'bookings:prune-photos {--dry-run : Hanya hitung, jangan hapus}';

    protected $description = 'Hapus foto kendaraan booking yang melewati masa simpan (bawaan 24 bulan)';

    public function handle(): int
    {
        $months = max(1, (int) config('booking.photo_retention_months', 24));
        $cutoff = today()->subMonthsNoOverflow($months);
        $dry = (bool) $this->option('dry-run');

        $files = 0;
        $rows = 0;

        Booking::withoutGlobalScopes()
            ->whereIn('status', ['completed', 'cancelled'])
            ->whereDate('preferred_date', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($bookings) use ($dry, &$files, &$rows) {
                $ids = $bookings->pluck('id');

                $intake = BookingIntakePhoto::whereIn('booking_id', $ids)->get();
                $handovers = BookingHandover::whereIn('booking_id', $ids)->whereNotNull('final_photo_path')->get();
                $messagePhotos = BookingMessagePhoto::whereHas('bookingMessage', fn ($q) => $q->whereIn('booking_id', $ids))->get();

                $files += $intake->count() + $handovers->count() + $messagePhotos->count();
                $rows += $intake->count() + $messagePhotos->count();

                if ($dry) {
                    return;
                }

                $disk = Storage::disk('public');

                foreach ($intake as $photo) {
                    $disk->delete($photo->path);
                    $photo->delete();
                }

                foreach ($handovers as $handover) {
                    $disk->delete($handover->final_photo_path);
                    $handover->update(['final_photo_path' => null]);
                }

                foreach ($messagePhotos as $photo) {
                    $disk->delete($photo->path);
                    $photo->delete();
                }
            });

        $this->info(($dry ? '[dry-run] ' : '') . "Foto lewat {$months} bulan (sebelum {$cutoff->toDateString()}): {$files} file" . ($dry ? ' akan dihapus.' : ' dihapus.'));

        return self::SUCCESS;
    }
}
