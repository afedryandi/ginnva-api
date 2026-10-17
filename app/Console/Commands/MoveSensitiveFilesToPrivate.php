<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\WarningLetter;
use App\Support\SensitiveFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Memindahkan berkas sensitif lama (selfie absen, lampiran izin/sakit, scan surat peringatan) dari disk publik ke disk privat
 * (Karyawan Fase 0, 2026-10-28). Path di database TIDAK berubah (hanya disk-nya), jadi tidak ada baris yang perlu diubah. Berkas disalin
 * dulu, ukurannya dibandingkan, baru yang lama dihapus; aman dijalankan berulang dan boleh dicoba dengan --dry-run.
 */
class MoveSensitiveFilesToPrivate extends Command
{
    protected $signature = 'files:move-sensitive-to-private {--dry-run : Hanya laporkan, tidak memindahkan}';

    protected $description = 'Pindahkan selfie absen, lampiran izin/sakit, dan scan surat peringatan dari disk publik ke disk privat';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $private = Storage::disk(SensitiveFiles::DISK);
        $public = Storage::disk('public');

        $paths = collect();

        foreach (['clock_in_photo', 'clock_out_photo'] as $column) {
            $paths = $paths->merge(Attendance::withoutGlobalScopes()->whereNotNull($column)->pluck($column));
        }

        $paths = $paths
            ->merge(LeaveRequest::withoutGlobalScopes()->whereNotNull('document')->pluck('document'))
            ->merge(WarningLetter::withoutGlobalScopes()->whereNotNull('document')->pluck('document'))
            ->filter()
            ->unique()
            ->values();

        $moved = 0;
        $alreadyPrivate = 0;
        $missing = 0;
        $failed = [];

        foreach ($paths as $path) {
            if ($private->exists($path)) {
                $alreadyPrivate++;

                // Salinan privat sudah ada; sisa salinan publik (bila ada) dibersihkan.
                if (! $dry && $public->exists($path) && $private->size($path) === $public->size($path)) {
                    $public->delete($path);
                }

                continue;
            }

            if (! $public->exists($path)) {
                $missing++;

                continue;
            }

            if ($dry) {
                $moved++;

                continue;
            }

            $stream = $public->readStream($path);

            if (! $stream || ! $private->writeStream($path, $stream)) {
                $failed[] = $path;

                continue;
            }

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($private->size($path) !== $public->size($path)) {
                $private->delete($path);
                $failed[] = $path;

                continue;
            }

            $public->delete($path);
            $moved++;
        }

        $verb = $dry ? 'akan dipindah' : 'dipindah';
        $this->info("{$moved} berkas {$verb}, {$alreadyPrivate} sudah privat, {$missing} tidak ditemukan di disk mana pun.");

        if ($failed) {
            $this->error(count($failed) . ' berkas GAGAL dipindah (tetap di tempat lama): ' . implode(', ', array_slice($failed, 0, 10)));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
