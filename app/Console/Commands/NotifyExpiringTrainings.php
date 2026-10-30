<?php

namespace App\Console\Commands;

use App\Models\TechnicianTraining;
use App\Models\User;
use App\Services\StaffNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Pengingat mingguan sertifikat installer (audit Kompetensi Installer, 2026-10-31): sertifikat yang berakhir dalam 30 hari ke depan atau
 * baru kedaluwarsa dalam 30 hari terakhir dan BELUM diperbarui (catatan yang sudah tergantikan tidak dihitung). Penerima: akses penuh
 * (semua toko) dan store manager (installer tokonya). Selama pesan sebelumnya belum dibaca, barisnya diperbarui, bukan ditumpuk.
 */
class NotifyExpiringTrainings extends Command
{
    protected $signature = 'training:notify-expiring';

    protected $description = 'Kirim pengingat mingguan sertifikat installer yang hampir atau baru kedaluwarsa ke akses penuh dan store manager';

    public function handle(): int
    {
        $trainings = TechnicianTraining::query()
            ->current()
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<=', today()->addDays(30))
            ->whereDate('valid_until', '>=', today()->subDays(30))
            ->whereHas('technician', fn ($q) => $q->withoutGlobalScopes()->where('status', '!=', 'inactive'))
            ->with('technician:id,name,store_id')
            ->orderBy('valid_until')
            ->get();

        if ($trainings->isEmpty()) {
            $this->info('Tidak ada sertifikat yang perlu diingatkan.');

            return self::SUCCESS;
        }

        $recipients = User::where('is_active', true)->with('roles')->get();
        $sent = 0;

        foreach ($recipients as $user) {
            $mine = match (true) {
                $user->isFullAccess() => $trainings,
                $user->isStoreManager() && $user->store_id => $trainings->filter(fn (TechnicianTraining $t) => $t->technician?->store_id === $user->store_id),
                default => collect(),
            };

            if ($mine->isEmpty()) {
                continue;
            }

            app(StaffNotifier::class)->toUsers([$user->id], 'Sertifikat Installer Segera Kedaluwarsa', $this->body($mine), [], 'training-expiry', 'warning');
            $sent++;
        }

        $this->info("{$trainings->count()} sertifikat, {$sent} penerima diingatkan.");

        return self::SUCCESS;
    }

    /** @param Collection<int, TechnicianTraining> $trainings */
    private function body(Collection $trainings): string
    {
        $lines = $trainings->take(4)->map(function (TechnicianTraining $t) {
            $when = $t->isExpired() ? 'kedaluwarsa ' : 'berlaku sampai ';

            return "{$t->technician?->name} ({$t->title}, {$when}{$t->valid_until->format('d M Y')})";
        })->implode('; ');

        $more = $trainings->count() > 4 ? ' dan ' . ($trainings->count() - 4) . ' lainnya' : '';

        return "{$trainings->count()} sertifikat installer hampir atau baru kedaluwarsa: {$lines}{$more}. Buka Karyawan > Kompetensi Installer.";
    }
}
