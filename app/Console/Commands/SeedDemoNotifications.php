<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\StaffInboxNotification;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Data demo untuk lonceng notifikasi panel admin (2026-11-13): membuat contoh notifikasi dari berbagai modul supaya tombol "Buka" dan
 * tampilan lonceng bisa dicoba tanpa menunggu kejadian nyata. Hanya menulis ke kotak masuk (tabel notifications), TIDAK mengirim push.
 * Semua baris diberi penanda "demo-seed" sehingga bisa dihapus bersih dengan --purge.
 *
 *   php artisan notifications:seed-demo                 -> ke semua pengguna akses penuh yang aktif
 *   php artisan notifications:seed-demo --user=a@b.com  -> hanya ke satu pengguna
 *   php artisan notifications:seed-demo --purge         -> hapus semua data demo
 */
class SeedDemoNotifications extends Command
{
    protected $signature = 'notifications:seed-demo {--user= : Email satu pengguna (default: semua pengguna akses penuh aktif)} {--purge : Hapus data demo, tidak membuat yang baru} {--force : Izinkan di lingkungan production}';

    protected $description = 'Buat (atau hapus dengan --purge) contoh notifikasi di lonceng panel admin untuk uji tampilan dan tombol Buka';

    private const MARKER = 'demo-seed';

    /** @var list<array{0: string, 1: string, 2: string, 3: int, 4: bool}> judul, isi, warna, menit yang lalu, sudah dibaca */
    private const SAMPLES = [
        ['Pengajuan Lembur Baru', 'Andi mengajukan lembur 13 Nov 2026 pukul 18:00–20:00. Putuskan sebelum jam mulai.', 'warning', 5, false],
        ['Pengajuan Dinas Luar Baru', 'Sari mengajukan dinas luar 13 Nov 2026 (Sehari penuh): kanvasing area Kelapa Gading.', 'warning', 18, false],
        ['Permintaan koreksi absensi baru', 'Budi mengajukan koreksi absensi tanggal 12 Nov 2026.', 'warning', 42, false],
        ['Pengajuan izin/cuti baru', 'Rina mengajukan IZ-2611-0007 (14 Nov–15 Nov 2026).', 'warning', 75, false],
        ['Booking Baru', 'Booking BKG-2611-0042 masuk: Toyota Fortuner, PPF Full Body.', 'info', 130, false],
        ['Permohonan Pembelian Baru', 'Permohonan pembelian PR-2611-0003 menunggu persetujuan.', 'warning', 240, true],
        ['Pengeluaran Menunggu Persetujuan', 'Pengeluaran Rp1.250.000 (Pembelian bahan baku) menunggu persetujuan direksi.', 'warning', 380, true],
        ['Surat Peringatan Diterbitkan', 'Surat Peringatan SP-2611-0002 untuk Dedi sudah diterbitkan.', 'danger', 600, true],
        ['Kontrak Karyawan Akan Berakhir', 'Kontrak Tono berakhir 30 hari lagi (12 Des 2026).', 'warning', 1440, true],
        ['Lead Baru', 'Lead baru dari Instagram: Honda CR-V, minat Kaca Film Ceramic.', 'info', 2000, true],
        ['Review Negatif Masuk', 'Review 2 bintang untuk Ginnva House: "Pengerjaan terlalu lama".', 'danger', 2900, true],
        ['Pengumuman uji tanpa tujuan', 'Notifikasi ini sengaja tidak punya tombol Buka karena judulnya tidak dikenali.', 'info', 3500, true],
    ];

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Ini lingkungan production. Tambahkan --force kalau memang ingin membuat atau menghapus data demo di sini.');

            return self::FAILURE;
        }

        $users = User::query()
            ->where('is_active', true)
            ->when($this->option('user'), fn ($q, $email) => $q->where('email', $email))
            ->get()
            ->filter(fn (User $u) => $this->option('user') ? true : $u->isFullAccess());

        if ($users->isEmpty()) {
            $this->warn('Tidak ada pengguna yang cocok.');

            return self::FAILURE;
        }

        if ($this->option('purge')) {
            $removed = 0;

            foreach ($users as $user) {
                $removed += $user->notifications()->where('data->viewData->coalesce_key', self::MARKER)->delete();
            }

            $this->info("Data demo dihapus: {$removed} notifikasi.");

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            // Dibuat ulang dari nol supaya menjalankan perintah dua kali tidak menggandakan data.
            $user->notifications()->where('data->viewData->coalesce_key', self::MARKER)->delete();

            foreach (self::SAMPLES as [$title, $body, $color, $minutesAgo, $read]) {
                $user->notify(new StaffInboxNotification('[Demo] ' . $title, $body, [], self::MARKER, $color));

                /** @var DatabaseNotification $row */
                $row = $user->notifications()->latest('created_at')->first();
                $row->forceFill([
                    'created_at' => now()->subMinutes($minutesAgo),
                    'updated_at' => now()->subMinutes($minutesAgo),
                    'read_at' => $read ? now()->subMinutes(max(0, $minutesAgo - 5)) : null,
                ])->save();
            }

            $this->line("  {$user->name} <{$user->email}>: " . count(self::SAMPLES) . ' notifikasi');
        }

        $this->info('Selesai. Hapus kapan saja dengan: php artisan notifications:seed-demo --purge');

        return self::SUCCESS;
    }
}
