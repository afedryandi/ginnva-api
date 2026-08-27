<?php

namespace App\Console\Commands;

use App\Filament\Resources\QuotationResource;
use App\Models\Quotation;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Alert lead Quotation yang masih 'new' (belum ditindaklanjuti) lebih dari
 * 24 jam — jalan harian (lihat routes/console.php). SEBELUMNYA tidak ada
 * cara sama sekali untuk tahu ada lead yang "didiamkan" — lihat audit
 * modul Quotation 2026-08-27.
 *
 * SENGAJA TIDAK pakai Acknowledgeable (beda dari NotifyExpiringMaterials)
 * — lead yang masih 'new' setelah ditinjau tetap 'new' sampai staff
 * benar-benar follow-up (ubah status), jadi notifikasi berulang tiap hari
 * itu memang perilaku yang diinginkan (pengingat terus sampai ditindak),
 * bukan bug spam.
 */
class NotifyStaleQuotations extends Command
{
    protected $signature = 'quotations:notify-stale';

    protected $description = 'Kirim notifikasi (push + bell Filament) untuk lead Quotation yang masih New lebih dari 24 jam';

    public function handle(): int
    {
        $staleQuotations = Quotation::where('status', 'new')
            ->where('created_at', '<=', now()->subHours(24))
            ->get();

        if ($staleQuotations->isEmpty()) {
            $this->info('Tidak ada lead Quotation yang telat di-follow-up.');
            return self::SUCCESS;
        }

        $push = app(PushNotificationService::class);

        // Per toko — staff toko itu saja yang di-push, direksi/super_admin
        // dapat lewat sendToStoreStaff() (selalu diikutkan, lihat
        // catatannya sendiri) + bell notifikasi Filament di bawah.
        foreach ($staleQuotations->groupBy('store_id') as $storeId => $group) {
            if (! $storeId) continue;

            $push->sendToStoreStaff(
                (int) $storeId,
                'Lead Belum Di-follow-up',
                "{$group->count()} lead quotation sudah lebih dari 24 jam belum ditindak.",
                ['type' => 'quotation_stale', 'route' => '/staff/quotations?status=new']
            );
        }

        // Bell notifikasi Filament — full-access + siapa pun yang punya
        // akses menu Quotation, sama pola dengan NotifyExpiringMaterials.
        $recipients = User::all()->filter(fn (User $user) => $user->isFullAccess()
            || $user->hasQuotationAccess());

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Lead Quotation Belum Di-follow-up')
                ->body("{$staleQuotations->count()} lead masih berstatus New lebih dari 24 jam.")
                ->warning()
                ->actions([
                    Action::make('view')
                        ->label('Lihat')
                        ->url(QuotationResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'new']]]))
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipient);
        }

        $this->info("Notifikasi terkirim untuk {$staleQuotations->count()} lead yang telat di-follow-up.");

        return self::SUCCESS;
    }
}