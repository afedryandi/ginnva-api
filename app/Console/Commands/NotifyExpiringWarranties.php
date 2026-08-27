<?php

namespace App\Console\Commands;

use App\Filament\Resources\WarrantyResource;
use App\Models\User;
use App\Models\Warranty;
use App\Services\PushNotificationService;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Alert garansi yang akan berakhir — jalan harian (lihat routes/console.php).
 * SEBELUMNYA tidak ada sama sekali, remaining_days sudah dihitung di model
 * tapi tidak pernah dipakai di titik keputusan otomatis mana pun. Lihat
 * audit modul Garansi 2026-08-27.
 *
 * Dua kanal, DUA pola berbeda dengan sengaja:
 * - Bell notifikasi staff: jendela 30 hari, BERULANG tiap hari sampai
 *   ditindak (sama pola dengan NotifyExpiringContracts) — begitu garansi
 *   diperpanjang lewat WarrantyResource ("Perpanjang"), expiry_date
 *   otomatis berubah dan baris itu keluar sendiri dari jendela ini, tidak
 *   perlu tracking "sudah ditinjau" terpisah.
 * - Push ke customer: HANYA SEKALI, tepat di H-30 (exact date match) —
 *   push notif ke end-customer BUKAN bell notifikasi staff, push
 *   berulang tiap hari selama 30 hari akan sangat mengganggu. Kalau nanti
 *   perlu reminder kedua (mis. H-7), tambahkan checkpoint tanggal baru,
 *   bukan ubah jadi rentang harian.
 */
class NotifyExpiringWarranties extends Command
{
    protected $signature = 'warranty:notify-expiring';

    protected $description = 'Kirim notifikasi (bell staff + push customer) untuk garansi yang akan berakhir';

    public function handle(): int
    {
        $push = app(PushNotificationService::class);

        $this->notifyStaff();
        $this->notifyCustomersAtCheckpoint($push, 30);
        $this->notifyCustomersAtCheckpoint($push, 7);

        return self::SUCCESS;
    }

    private function notifyStaff(): void
    {
        $expiring = Warranty::query()
            ->where('review_status', 'approved')
            ->whereDate('expiry_date', '>=', now())
            ->whereDate('expiry_date', '<=', now()->addDays(30))
            ->get();

        if ($expiring->isEmpty()) {
            $this->info('Tidak ada garansi approved yang mendekati berakhir.');
            return;
        }

        // Per toko — supaya staff toko A tidak dibanjiri notifikasi soal
        // garansi toko B (sama pola dengan NotifyStaleQuotations).
        foreach ($expiring->groupBy('store_id') as $storeId => $group) {
            $recipients = User::all()->filter(fn (User $user) => $user->isFullAccess()
                || ($storeId && (int) $user->store_id === (int) $storeId && $user->hasMenuAccess(WarrantyResource::class)));

            foreach ($recipients as $recipient) {
                Notification::make()
                    ->title('Garansi Akan Berakhir')
                    ->body("{$group->count()} garansi akan berakhir dalam 30 hari ke depan.")
                    ->warning()
                    ->actions([
                        Action::make('view')
                            ->label('Lihat')
                            ->url(WarrantyResource::getUrl('index'))
                            ->markAsRead(),
                    ])
                    ->sendToDatabase($recipient);
            }
        }

        $this->info("Bell notifikasi staff terkirim untuk {$expiring->count()} garansi yang mendekati berakhir.");
    }

    private function notifyCustomersAtCheckpoint(PushNotificationService $push, int $daysBefore): void
    {
        $warranties = Warranty::query()
            ->where('review_status', 'approved')
            ->whereNotNull('customer_id')
            ->whereDate('expiry_date', now()->addDays($daysBefore)->toDateString())
            ->get();

        foreach ($warranties as $warranty) {
            $push->sendToCustomer(
                $warranty->customer_id,
                'Garansi Akan Berakhir',
                "Garansi {$warranty->warranty_code} Anda akan berakhir dalam {$daysBefore} hari lagi.",
                ['type' => 'warranty_expiring', 'route' => "/account/warranty-detail?id={$warranty->id}"]
            );
        }

        if ($warranties->isNotEmpty()) {
            $this->info("Push H-{$daysBefore} terkirim untuk {$warranties->count()} garansi.");
        }
    }
}
