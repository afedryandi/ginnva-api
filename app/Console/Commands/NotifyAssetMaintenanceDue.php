<?php

namespace App\Console\Commands;

use App\Filament\Resources\AssetResource;
use App\Models\Asset;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Sebelumnya status "Diperbaiki" adalah jalan buntu — begitu ditandai,
 * tidak ada pengingat apa pun untuk mengecek aset itu lagi kapan harus
 * kembali "Aktif". Command ini jalan harian (lihat routes/console.php),
 * kirim ke bell notifikasi Filament untuk aset yang next_maintenance_date-
 * nya sudah jatuh tempo hari ini atau sebelumnya.
 */
class NotifyAssetMaintenanceDue extends Command
{
    protected $signature = 'assets:notify-maintenance-due';

    protected $description = 'Kirim notifikasi ke admin untuk aset yang jadwal maintenance-nya sudah jatuh tempo';

    public function handle(): int
    {
        $dueAssets = Asset::query()
            ->whereNotNull('next_maintenance_date')
            ->whereDate('next_maintenance_date', '<=', now())
            ->where(fn ($q) => $q->whereNull('reviewed_at')->orWhereColumn('reviewed_at', '<', 'updated_at'))
            ->with('store:id,name')
            ->get();

        if ($dueAssets->isEmpty()) {
            $this->info('Tidak ada aset yang jadwal maintenance-nya jatuh tempo hari ini.');

            return self::SUCCESS;
        }

        $recipients = User::all()->filter(fn (User $user) => $user->isFullAccess()
            || $user->hasMenuAccess(AssetResource::class));

        $listText = $dueAssets->take(10)
            ->map(fn (Asset $a) => "{$a->name} ({$a->asset_tag})" . ($a->store ? " — {$a->store->name}" : ''))
            ->implode(', ');

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title($dueAssets->count() . ' Aset Perlu Maintenance')
                ->body($listText . ($dueAssets->count() > 10 ? ', dan ' . ($dueAssets->count() - 10) . ' lainnya.' : '.'))
                ->warning()
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('Lihat')
                        ->url(AssetResource::getUrl('index'))
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipient);
        }

        $this->info("Notifikasi terkirim ke {$recipients->count()} admin ({$dueAssets->count()} aset jatuh tempo).");

        return self::SUCCESS;
    }
}