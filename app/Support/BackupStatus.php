<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Status cadangan database untuk kartu "Cadangan Database" di Dashboard (akses penuh): berkas cadangan terakhir
 * di server ini (backup:database, jadwal harian 03:00), umurnya, dan apakah salinan off-site sudah diatur.
 * Hanya membaca nama dan waktu berkas; isi cadangan tidak pernah dibuka.
 */
class BackupStatus
{
    /** Cadangan lebih tua dari ini dianggap terlambat (jadwal harian + toleransi). */
    public const STALE_AFTER_HOURS = 30;

    /**
     * @return array{
     *     found: bool, at: ?CarbonInterface, size_mb: ?float, age_hours: ?int, stale: bool,
     *     count: int, offsite_configured: bool, state: 'ok'|'stale'|'missing'
     * }
     */
    public static function latest(?string $directory = null, ?CarbonInterface $now = null): array
    {
        $directory ??= storage_path('app/backups');
        $now ??= now();

        $files = is_dir($directory)
            ? collect(File::files($directory))
                ->filter(fn ($file) => str_starts_with($file->getFilename(), 'ginnva_backup_') && str_ends_with($file->getFilename(), '.sql.gz'))
                ->sortByDesc(fn ($file) => $file->getMTime())
                ->values()
            : collect();

        $offsite = filled(config('services.backup.rclone_remote'));

        if ($files->isEmpty()) {
            return ['found' => false, 'at' => null, 'size_mb' => null, 'age_hours' => null, 'stale' => true, 'count' => 0, 'offsite_configured' => $offsite, 'state' => 'missing'];
        }

        $latest = $files->first();
        $at = Carbon::createFromTimestamp($latest->getMTime());
        $ageHours = (int) $at->diffInHours($now, true);
        $stale = $ageHours >= self::STALE_AFTER_HOURS;

        return [
            'found' => true,
            'at' => $at,
            'size_mb' => round($latest->getSize() / 1024 / 1024, 2),
            'age_hours' => $ageHours,
            'stale' => $stale,
            'count' => $files->count(),
            'offsite_configured' => $offsite,
            'state' => $stale ? 'stale' : 'ok',
        ];
    }
}
