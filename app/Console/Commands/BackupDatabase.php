<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Otomasi SOP Backup Database manual yang sudah ada di RUNBOOK.md §5 —
 * dibuat karena audit framework 2026-09-14 ("Jadwal backup database
 * otomatis") menemukan backup production SEPENUHNYA bergantung pada
 * seseorang ingat menjalankan perintah manual, tidak ada jadwal
 * otomatis sama sekali.
 *
 * Cuma jalan kalau koneksi DB 'mysql' (production) — di lokal/CI yang
 * pakai 'sqlite' perintah ini no-op supaya aman dijalankan di environment
 * mana pun tanpa perlu dicek manual dulu.
 *
 * Upload ke remote (rclone) OPSIONAL — kalau RCLONE_BACKUP_REMOTE belum
 * diisi di .env, backup tetap dibuat & disimpan lokal (lebih baik ada
 * backup lokal daripada tidak sama sekali), cuma dicatat warning di log
 * supaya kelihatan di Sentry/log monitoring bahwa belum ada salinan
 * off-site untuk hari itu.
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Backup database production harian (dump + gzip + upload ke remote opsional), retensi lokal otomatis';

    public function handle(): int
    {
        if (config('database.default') !== 'mysql') {
            $this->info('Koneksi database bukan mysql (kemungkinan lokal/CI) — backup dilewati.');

            return self::SUCCESS;
        }

        $connection = config('database.connections.mysql');
        $backupDir = storage_path('app/backups');
        File::ensureDirectoryExists($backupDir);

        $timestamp = now()->format('Ymd_His');
        $sqlPath = "{$backupDir}/ginnva_backup_{$timestamp}.sql";
        $gzPath = "{$sqlPath}.gz";

        // --no-tablespaces: hindari error "Access denied ... PROCESS
        // privilege" di mysqldump versi baru kalau DB user production
        // tidak diberi privilege PROCESS global (umum di shared/managed
        // hosting) — sama catatan yang sudah ada di RUNBOOK.md §5.
        // Password dikirim lewat MYSQL_PWD env var (bukan argumen
        // --password= di command line) supaya tidak muncul di process
        // list (`ps aux`) server selama proses berjalan -- lebih aman
        // dari SOP manual RUNBOOK yang minta password interaktif.
        $dumpResult = Process::timeout(600)
            ->env(['MYSQL_PWD' => $connection['password']])
            ->run([
                'mysqldump',
                '--no-tablespaces',
                '-h', $connection['host'],
                '-P', (string) $connection['port'],
                '-u', $connection['username'],
                $connection['database'],
            ]);

        if (! $dumpResult->successful()) {
            Log::error('[BackupDatabase] mysqldump gagal', [
                'exit_code' => $dumpResult->exitCode(),
                'error' => $dumpResult->errorOutput(),
            ]);
            $this->error('mysqldump gagal: ' . $dumpResult->errorOutput());

            return self::FAILURE;
        }

        File::put($sqlPath, $dumpResult->output());

        if (! File::exists($sqlPath) || File::size($sqlPath) === 0) {
            Log::error('[BackupDatabase] File dump kosong/tidak terbentuk', ['path' => $sqlPath]);
            $this->error('File dump kosong — backup dibatalkan.');

            return self::FAILURE;
        }

        // gzip lewat proses shell (bukan ext-zlib) supaya hasilnya
        // persis sama dengan SOP manual RUNBOOK (`| gzip`), gampang
        // dibuka manual pakai `gunzip` kalau perlu inspeksi cepat.
        $gzipResult = Process::timeout(300)->run(['gzip', '-f', $sqlPath]);

        if (! $gzipResult->successful() || ! File::exists($gzPath)) {
            Log::error('[BackupDatabase] gzip gagal', ['error' => $gzipResult->errorOutput()]);
            $this->error('gzip gagal: ' . $gzipResult->errorOutput());

            return self::FAILURE;
        }

        $sizeMb = round(File::size($gzPath) / 1024 / 1024, 2);
        $this->info("Backup dibuat: {$gzPath} ({$sizeMb} MB)");

        // Upload ke remote — OPSIONAL, lihat catatan class di atas.
        $remote = config('services.backup.rclone_remote');
        $uploaded = false;

        if ($remote) {
            $uploadResult = Process::timeout(600)->run(['rclone', 'copy', $gzPath, $remote]);

            if ($uploadResult->successful()) {
                $uploaded = true;
                $this->info("Berhasil upload ke remote: {$remote}");
            } else {
                Log::error('[BackupDatabase] Upload rclone gagal — backup TETAP ada secara lokal', [
                    'remote' => $remote,
                    'error' => $uploadResult->errorOutput(),
                ]);
                $this->error('Upload rclone gagal (backup lokal tetap aman): ' . $uploadResult->errorOutput());
            }
        } else {
            Log::warning('[BackupDatabase] RCLONE_BACKUP_REMOTE belum diisi di .env — backup HANYA tersimpan lokal, tidak ada salinan off-site.');
            $this->warn('RCLONE_BACKUP_REMOTE belum diisi — backup cuma tersimpan lokal di server (bukan off-site).');
        }

        // Retensi lokal — hapus dump lama supaya disk tidak membengkak
        // tanpa batas (remote yang jadi penyimpanan jangka panjang,
        // lokal cuma buffer beberapa hari terakhir untuk restore cepat).
        $retentionDays = (int) config('services.backup.retention_days', 14);
        $this->pruneOldBackups($backupDir, $retentionDays);

        Log::info('[BackupDatabase] Selesai', [
            'file' => basename($gzPath),
            'size_mb' => $sizeMb,
            'uploaded_offsite' => $uploaded,
        ]);

        return self::SUCCESS;
    }

    private function pruneOldBackups(string $backupDir, int $retentionDays): void
    {
        $cutoff = now()->subDays($retentionDays);

        foreach (File::files($backupDir) as $file) {
            if (! Str::startsWith($file->getFilename(), 'ginnva_backup_')) {
                continue;
            }

            if (Carbon::createFromTimestamp($file->getMTime())->lt($cutoff)) {
                File::delete($file->getPathname());
            }
        }
    }
}
