<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ScheduledPriceChangeService;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Harian (lihat routes/console.php): menerapkan perubahan harga terjadwal yang tanggal efektifnya sudah tiba.
 * Hasilnya diberitahukan ke akses penuh lewat bel notifikasi panel; kegagalan membuat perintah ini keluar dengan FAILURE.
 */
class ApplyScheduledPriceChanges extends Command
{
    protected $signature = 'prices:apply-scheduled';

    protected $description = 'Terapkan perubahan harga produk film yang sudah jatuh tempo';

    public function handle(ScheduledPriceChangeService $service): int
    {
        $result = $service->runDue();

        foreach ($result['applied'] as $change) {
            $this->info("\"{$change->name}\": {$change->affected_prices} harga diubah ({$change->percent}%).");
        }

        foreach ($result['failures'] as $id => $message) {
            $this->error("Perubahan harga #{$id} gagal: {$message}");
        }

        if ($result['applied']->isEmpty() && $result['failures'] === []) {
            $this->info('Tidak ada perubahan harga yang jatuh tempo.');

            return self::SUCCESS;
        }

        $recipients = User::query()->where('is_active', true)->role(['super_admin', 'direksi'])->get();

        foreach ($result['applied'] as $change) {
            Notification::make()
                ->title('Perubahan harga berlaku')
                ->body("\"{$change->name}\": {$change->affected_prices} harga diubah ({$change->percent}%).")
                ->success()
                ->sendToDatabase($recipients);
        }

        if ($result['failures'] !== []) {
            Notification::make()
                ->title('Perubahan harga terjadwal gagal diterapkan')
                ->body(count($result['failures']) . ' perubahan gagal. Periksa menu Perubahan Harga Terjadwal.')
                ->danger()
                ->sendToDatabase($recipients);
        }

        return $result['failures'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
