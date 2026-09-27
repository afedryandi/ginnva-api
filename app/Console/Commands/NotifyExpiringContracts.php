<?php

namespace App\Console\Commands;

use App\Filament\Resources\ContractExtensionResource;
use App\Models\User;
use App\Services\PushNotificationService;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Alert kontrak karyawan yang akan berakhir dalam 30 hari — jalan harian
 * (lihat routes/console.php). TIDAK pakai Acknowledgeable/reviewed_at
 * seperti NotifyExpiringMaterials — begitu kontraknya diperpanjang lewat
 * ContractExtensionResource, contract_end_date otomatis berubah dan baris
 * itu keluar sendiri dari jendela 30 hari ini, jadi tidak perlu tracking
 * "sudah ditinjau" terpisah.
 *
 * is_active=false (resign/dinonaktifkan) DIKECUALIKAN dari kandidat
 * kontrak berakhir — tanpa ini, karyawan yang sudah keluar tapi
 * contract_end_date lamanya kebetulan jatuh dalam 30 hari ke depan akan
 * terus memicu notifikasi ke semua admin setiap hari selamanya, padahal
 * tidak relevan lagi. Lihat pola sama di MarkAbsences & generate payroll
 * bulanan (PayrollResource).
 *
 * Gap standar enterprise diperbaiki 2026-09-27 (audit Perpanjang
 * Kontrak): SEBELUMNYA notifikasi cuma ke admin/HR, karyawan yang
 * bersangkutan tidak pernah tahu kontraknya mendekati habis kecuali
 * ditanya langsung. Sekarang jendela <=7 hari dipisah jadi notifikasi
 * "mendesak" ke admin (severity beda dari yang 8-30 hari), DAN setiap
 * karyawan yang kontraknya masuk jendela 30 hari dapat push langsung.
 */
class NotifyExpiringContracts extends Command
{
    protected $signature = 'contracts:notify-expiring';

    protected $description = 'Kirim notifikasi ke admin & karyawan kalau ada kontrak karyawan yang akan berakhir dalam 30 hari';

    public function handle(): int
    {
        $expiringUsers = User::query()
            ->where('is_active', true)
            ->whereNotNull('contract_end_date')
            ->whereDate('contract_end_date', '>=', now())
            ->whereDate('contract_end_date', '<=', now()->addDays(30))
            ->get();

        if ($expiringUsers->isEmpty()) {
            $this->info('Tidak ada kontrak karyawan yang mendekati berakhir.');
            return self::SUCCESS;
        }

        $urgent = $expiringUsers->filter(fn (User $u) => today()->diffInDays($u->contract_end_date, false) <= 7);
        $normal = $expiringUsers->reject(fn (User $u) => $urgent->contains('id', $u->id));

        $recipients = User::where('is_active', true)->get()->filter(fn (User $user) => $user->isFullAccess()
            || $user->hasMenuAccess(ContractExtensionResource::class));

        foreach ([
            ['group' => $urgent, 'title' => 'Kontrak Karyawan SEGERA Berakhir (≤7 hari)', 'urgent' => true],
            ['group' => $normal, 'title' => 'Kontrak Karyawan Akan Berakhir (≤30 hari)', 'urgent' => false],
        ] as $bucket) {
            if ($bucket['group']->isEmpty()) {
                continue;
            }

            $names = $bucket['group']->map(fn (User $u) => "{$u->name} ({$u->contract_end_date->format('d M Y')})")->implode(', ');

            foreach ($recipients as $recipient) {
                $notification = Notification::make()
                    ->title($bucket['title'])
                    ->body("{$bucket['group']->count()} karyawan: {$names}")
                    ->actions([
                        \Filament\Notifications\Actions\Action::make('view')
                            ->label('Lihat')
                            ->url(ContractExtensionResource::getUrl('index'))
                            ->markAsRead(),
                    ]);

                $bucket['urgent'] ? $notification->danger() : $notification->warning();

                $notification->sendToDatabase($recipient);
            }
        }

        $pushService = app(PushNotificationService::class);
        foreach ($expiringUsers as $user) {
            $daysLeft = today()->diffInDays($user->contract_end_date, false);
            $pushService->sendToUsers(
                [$user->id],
                'Kontrak Kerja Anda Akan Berakhir',
                "Kontrak Anda berakhir {$daysLeft} hari lagi (" . $user->contract_end_date->translatedFormat('d M Y') . '). Hubungi HR/atasan Anda untuk informasi perpanjangan.'
            );
        }

        $this->info("Notifikasi terkirim ke {$recipients->count()} admin & {$expiringUsers->count()} karyawan ({$expiringUsers->count()} kontrak mendekati berakhir, {$urgent->count()} mendesak ≤7 hari).");

        return self::SUCCESS;
    }
}
