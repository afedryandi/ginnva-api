<?php

namespace App\Console\Commands;

use App\Models\OtpCode;
use Illuminate\Console\Command;

/**
 * Audit framework 2026-09-14, "Retensi & penghapusan data historis" --
 * baris OtpCode yang sudah expired SEBELUMNYA tidak pernah dihapus,
 * menumpuk permanen di database (kode OTP lama sekalipun sudah tidak
 * bisa dipakai). Dijadwalkan harian (lihat routes/console.php).
 *
 * Retensi 7 hari SETELAH expired (bukan langsung saat expired) --
 * jaga-jaga kalau ada kebutuhan debug/investigasi singkat pasca
 * insiden login (mis. komplain user "OTP tidak masuk").
 */
class PruneExpiredOtpCodes extends Command
{
    protected $signature = 'otp:prune-expired';

    protected $description = 'Hapus kode OTP yang sudah expired lebih dari 7 hari';

    public function handle(): int
    {
        $deleted = OtpCode::where('expires_at', '<', now()->subDays(7))->delete();

        $this->info("{$deleted} kode OTP kedaluwarsa dihapus.");

        return self::SUCCESS;
    }
}
