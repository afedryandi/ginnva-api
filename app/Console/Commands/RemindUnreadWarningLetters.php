<?php

namespace App\Console\Commands;

use App\Models\WarningLetter;
use App\Services\StaffNotifier;
use Illuminate\Console\Command;

/**
 * Pengingat ke karyawan yang Surat Peringatannya belum dibaca (audit Surat Peringatan, 2026-10-31). Surat yang belum dibaca dan belum
 * dibatalkan diingatkan pada umur 3, 7, dan 14 hari sejak dicatat (bukan tiap hari), satu pesan per karyawan walau suratnya lebih dari
 * satu. Karyawan nonaktif tidak diingatkan. Ketukan pada pesan membuka layar Riwayat Surat Peringatan di aplikasi.
 */
class RemindUnreadWarningLetters extends Command
{
    /** Umur surat (hari sejak dicatat) saat karyawan diingatkan. */
    public const REMINDER_DAYS = [3, 7, 14];

    protected $signature = 'warnings:remind-unread';

    protected $description = 'Ingatkan karyawan yang Surat Peringatannya belum dibaca (hari ke-3, 7, dan 14)';

    public function handle(): int
    {
        $due = WarningLetter::withoutGlobalScopes()
            ->whereNull('acknowledged_at')
            ->whereNull('cancelled_at')
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->get()
            ->filter(fn (WarningLetter $w) => in_array((int) $w->created_at->copy()->startOfDay()->diffInDays(today()), self::REMINDER_DAYS, true))
            ->groupBy('user_id');

        if ($due->isEmpty()) {
            $this->info('Tidak ada Surat Peringatan yang perlu diingatkan.');

            return self::SUCCESS;
        }

        foreach ($due as $userId => $letters) {
            $body = $letters->count() === 1
                ? 'Anda punya 1 Surat Peringatan (' . $letters->first()->warning_number . ') yang belum dibaca. Buka Absensi > Riwayat Surat Peringatan.'
                : "Anda punya {$letters->count()} Surat Peringatan yang belum dibaca. Buka Absensi > Riwayat Surat Peringatan.";

            app(StaffNotifier::class)->toUsers(
                [$userId],
                'Surat Peringatan Belum Dibaca',
                $body,
                ['type' => 'warning_letter', 'route' => '/staff/attendance/warning-letters'],
                'warning-unread',
                'warning'
            );
        }

        $this->info("{$due->count()} karyawan diingatkan.");

        return self::SUCCESS;
    }
}
