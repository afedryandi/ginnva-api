<?php

namespace App\Console\Commands;

use App\Filament\Resources\WarrantyResource;
use App\Models\User;
use App\Models\Warranty;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Gap ditutup 2026-10-01 (audit Maintenance PPF, "standar enterprise") --
 * SEBELUMNYA tidak ada cara bagi staff melihat customer yang berulang kali
 * forfeit (tidak pernah datang/konfirmasi) siklus maintenance otomatisnya,
 * selain buka detail garansi satu per satu. Jalan harian (lihat
 * routes/console.php).
 *
 * "Berulang" = 2 occurrence TERAKHIR (sequence tertinggi) sama-sama
 * berstatus 'forfeited', TANPA ada 'completed'/'confirmed' di antaranya --
 * indikasi customer kemungkinan tidak lagi berminat, bukan cuma 1x
 * kebetulan lewat tanggal. SENGAJA berulang tiap hari sampai ditindak
 * (sama pola dengan NotifyStaleQuotations) -- selama masih ada occurrence
 * forfeited beruntun yang belum ditindak, pengingat tetap relevan.
 */
class NotifyRepeatedMaintenanceForfeits extends Command
{
    protected $signature = 'warranty:notify-maintenance-followup';

    protected $description = 'Kirim notifikasi bell staff untuk garansi PPF dengan 2+ occurrence maintenance forfeited berturut-turut';

    private const CONSECUTIVE_THRESHOLD = 2;

    public function handle(): int
    {
        $warranties = Warranty::where('product_category', 'ppf')
            ->whereHas('maintenanceSchedules', fn ($q) => $q->where('status', 'forfeited'))
            ->with('maintenanceSchedules')
            ->get()
            ->filter(function (Warranty $warranty) {
                $tail = $warranty->maintenanceSchedules->sortByDesc('sequence')->take(self::CONSECUTIVE_THRESHOLD);

                return $tail->count() === self::CONSECUTIVE_THRESHOLD
                    && $tail->every(fn ($s) => $s->status === 'forfeited');
            });

        if ($warranties->isEmpty()) {
            $this->info('Tidak ada garansi dengan forfeit maintenance berturut-turut.');
            return self::SUCCESS;
        }

        foreach ($warranties->groupBy('store_id') as $storeId => $group) {
            $recipients = User::all()->filter(fn (User $user) => $user->isFullAccess()
                || ($storeId && (int) $user->store_id === (int) $storeId && $user->hasMenuAccess(WarrantyResource::class)));

            $threshold = self::CONSECUTIVE_THRESHOLD;

            foreach ($recipients as $recipient) {
                foreach ($group as $warranty) {
                    Notification::make()
                        ->title('Maintenance PPF Terlewat Berulang')
                        ->body("Garansi #{$warranty->warranty_code} sudah {$threshold}x berturut-turut tidak hadir/konfirmasi maintenance. Pertimbangkan follow-up manual.")
                        ->warning()
                        ->actions([
                            Action::make('view')
                                ->label('Lihat')
                                ->url(WarrantyResource::getUrl('view', ['record' => $warranty]))
                                ->markAsRead(),
                        ])
                        ->sendToDatabase($recipient);
                }
            }
        }

        $this->info("Notifikasi terkirim untuk {$warranties->count()} garansi dengan forfeit berturut-turut.");

        return self::SUCCESS;
    }
}
