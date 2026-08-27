<?php

namespace App\Console\Commands;

use App\Filament\Resources\ContractExtensionResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Alert kontrak karyawan yang akan berakhir dalam 30 hari — jalan harian
 * (lihat routes/console.php). TIDAK pakai Acknowledgeable/reviewed_at
 * seperti NotifyExpiringMaterials — begitu kontraknya diperpanjang lewat
 * ContractExtensionResource, contract_end_date otomatis berubah dan baris
 * itu keluar sendiri dari jendela 30 hari ini, jadi tidak perlu tracking
 * "sudah ditinjau" terpisah.
 */
class NotifyExpiringContracts extends Command
{
    protected $signature = 'contracts:notify-expiring';

    protected $description = 'Kirim notifikasi ke admin kalau ada kontrak karyawan yang akan berakhir dalam 30 hari';

    public function handle(): int
    {
        $expiringUsers = User::query()
            ->whereNotNull('contract_end_date')
            ->whereDate('contract_end_date', '>=', now())
            ->whereDate('contract_end_date', '<=', now()->addDays(30))
            ->get();

        if ($expiringUsers->isEmpty()) {
            $this->info('Tidak ada kontrak karyawan yang mendekati berakhir.');
            return self::SUCCESS;
        }

        $names = $expiringUsers->map(fn (User $u) => "{$u->name} ({$u->contract_end_date->format('d M Y')})")->implode(', ');

        $recipients = User::all()->filter(fn (User $user) => $user->isFullAccess()
            || $user->hasMenuAccess(ContractExtensionResource::class));

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Kontrak Karyawan Akan Berakhir')
                ->body("{$expiringUsers->count()} karyawan: {$names}")
                ->warning()
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('Lihat')
                        ->url(ContractExtensionResource::getUrl('index'))
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipient);
        }

        $this->info("Notifikasi terkirim ke {$recipients->count()} admin ({$expiringUsers->count()} kontrak mendekati berakhir).");

        return self::SUCCESS;
    }
}
