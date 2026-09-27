<?php

namespace App\Console\Commands;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * Gap standar enterprise diperbaiki 2026-09-27 (audit Perpanjang
 * Kontrak) -- SEBELUMNYA NotifyExpiringContracts (H-30) cuma
 * mengirim notifikasi, is_active TIDAK PERNAH otomatis di-set false
 * begitu contract_end_date benar-benar lewat tanpa diperpanjang.
 * Karyawan kontrak yang sudah expired tetap bisa login (panel &
 * mobile), tetap ikut ter-generate Payroll & Jadwal Kerja, kecuali
 * admin ingat menonaktifkan manual lewat UserResource.
 *
 * HANYA menyentuh karyawan dengan employeeType->has_end_date = true
 * (tipe kontrak, bukan karyawan tetap) -- kalau contract_end_date
 * kebetulan terisi di karyawan tetap (mis. salah input lalu tipe
 * karyawannya diganti), TIDAK ikut dinonaktifkan otomatis, supaya
 * tidak ada auto-deactivation yang salah sasaran.
 *
 * Idempotent secara alami -- kondisi is_active=true berarti begitu
 * baris ini menonaktifkan seseorang, baris itu otomatis keluar dari
 * kandidat run berikutnya, tidak perlu state "sudah diproses" terpisah.
 */
class DeactivateExpiredContracts extends Command
{
    protected $signature = 'contracts:deactivate-expired';

    protected $description = 'Nonaktifkan otomatis karyawan kontrak (PKWT) yang tanggal akhir kontraknya sudah lewat tanpa diperpanjang';

    public function handle(): int
    {
        $expired = User::query()
            ->where('is_active', true)
            ->whereNotNull('contract_end_date')
            ->whereDate('contract_end_date', '<', today())
            ->whereHas('employeeType', fn ($q) => $q->where('has_end_date', true))
            ->get();

        if ($expired->isEmpty()) {
            $this->info('Tidak ada kontrak karyawan yang perlu dinonaktifkan.');

            return self::SUCCESS;
        }

        foreach ($expired as $user) {
            $user->update(['is_active' => false]);
        }

        $names = $expired->map(fn (User $u) => "{$u->name} (berakhir {$u->contract_end_date->format('d M Y')})")->implode(', ');

        $recipients = User::where('is_active', true)->get()->filter(fn (User $u) => $u->isFullAccess()
            || $u->hasMenuAccess(UserResource::class));

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Karyawan Dinonaktifkan Otomatis (Kontrak Berakhir)')
                ->body("{$expired->count()} karyawan: {$names}. Tidak diperpanjang sebelum tanggal berakhir, akses dicabut otomatis.")
                ->danger()
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('Lihat')
                        ->url(UserResource::getUrl('index'))
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipient);
        }

        $this->info("{$expired->count()} karyawan dinonaktifkan otomatis, notifikasi terkirim ke {$recipients->count()} admin.");

        return self::SUCCESS;
    }
}
