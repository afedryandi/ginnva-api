<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DepreciationPostingService;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Jalankan bulanan (lihat routes/console.php) — posting Beban
 * Penyusutan Aset Tetap otomatis lewat DepreciationPostingService.
 * Dijadwalkan tanggal 1 SETIAP bulan untuk BULAN LALU (bukan bulan
 * berjalan) — supaya "bulan yang disusutkan" selalu sudah selesai
 * penuh, sama filosofi dengan PayrollResource's "Generate Payroll
 * Bulanan" yang memperingatkan hal serupa.
 */
class PostAssetDepreciation extends Command
{
    protected $signature = 'assets:post-depreciation
        {--month= : Bulan yang diposting, format YYYY-MM-DD (default: bulan lalu)}';

    protected $description = 'Posting Beban Penyusutan Aset Tetap otomatis ke Jurnal Umum untuk 1 bulan';

    public function handle(): int
    {
        $month = $this->option('month')
            ? Carbon::parse($this->option('month'))
            : now()->subMonth();

        $result = app(DepreciationPostingService::class)->postForMonth($month);

        $this->info("{$result['posted']} aset berhasil diposting penyusutannya untuk " . $month->translatedFormat('F Y') . '.');

        if ($result['skipped'] > 0) {
            $this->warn("{$result['skipped']} aset dilewati (sudah pernah diposting bulan ini, sudah habis disusutkan, atau belum dihubungkan ke Bagan Akun).");
        }

        foreach ($result['messages'] as $message) {
            $this->warn("- {$message}");
        }

        // Notifikasi bell Filament kalau ada aset yang gagal/dilewati
        // karena belum dihubungkan ke Bagan Akun — supaya admin tahu
        // ada tindakan yang perlu diambil, bukan cuma diam di log
        // command yang jarang dicek manual.
        if (! empty($result['messages'])) {
            $recipients = User::where('is_active', true)->get()->filter(fn (User $u) => $u->isFullAccess());

            foreach ($recipients as $recipient) {
                Notification::make()
                    ->title('Penyusutan Aset Tetap: ada yang dilewati')
                    ->body(count($result['messages']) . ' aset dilewati saat posting penyusutan bulan ' . $month->translatedFormat('F Y') . ' — periksa menu Aset Tetap.')
                    ->warning()
                    ->sendToDatabase($recipient);
            }
        }

        return self::SUCCESS;
    }
}
