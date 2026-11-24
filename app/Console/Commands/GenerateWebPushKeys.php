<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Membuat pasangan kunci VAPID untuk push web PWA dan menuliskannya ke .env (nilainya tidak ditampilkan). Dijalankan sekali per lingkungan;
 * mengganti kunci membuat semua langganan perangkat tidak berlaku, jadi perintah menolak menimpa kecuali dengan --force.
 */
class GenerateWebPushKeys extends Command
{
    protected $signature = 'webpush:keys {--force : Timpa kunci yang sudah ada (semua perangkat harus berlangganan ulang)}';

    protected $description = 'Buat kunci VAPID push web dan simpan di .env';

    public function handle(): int
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            $this->error('Berkas .env tidak ditemukan.');

            return self::FAILURE;
        }

        $env = (string) file_get_contents($path);

        if (preg_match('/^WEBPUSH_VAPID_PRIVATE_KEY=.+$/m', $env) && ! $this->option('force')) {
            $this->warn('Kunci sudah ada di .env. Tidak ditimpa (pakai --force bila memang perlu, dan semua perangkat harus berlangganan ulang).');

            return self::SUCCESS;
        }

        $keys = VAPID::createVapidKeys();
        $env = $this->put($env, 'WEBPUSH_VAPID_PUBLIC_KEY', $keys['publicKey']);
        $env = $this->put($env, 'WEBPUSH_VAPID_PRIVATE_KEY', $keys['privateKey']);

        if (! preg_match('/^WEBPUSH_SUBJECT=.+$/m', $env)) {
            $env = $this->put($env, 'WEBPUSH_SUBJECT', 'mailto:' . (config('mail.from.address') ?: 'admin@ginnva.id'));
        }

        file_put_contents($path, $env);
        $this->info('Kunci VAPID dibuat dan disimpan di .env. Jalankan `php artisan config:clear` (atau optimize) agar terbaca.');

        return self::SUCCESS;
    }

    private function put(string $env, string $key, string $value): string
    {
        if (preg_match("/^{$key}=.*$/m", $env)) {
            return (string) preg_replace("/^{$key}=.*$/m", "{$key}={$value}", $env);
        }

        return rtrim($env, "\r\n") . "\n{$key}={$value}\n";
    }
}
