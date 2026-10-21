<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Jam tenang promosi pelanggan (Notifikasi Fase 4). Konfigurasi di config/marketing.php (promo_quiet_hours). Rentang yang melewati
 * tengah malam (mis. 21:00 sampai 08:00) didukung; awal sama dengan akhir berarti tidak ada jam tenang.
 */
class QuietHours
{
    public static function active(?CarbonInterface $at = null): bool
    {
        $config = config('marketing.promo_quiet_hours');

        if (! ($config['enabled'] ?? false)) {
            return false;
        }

        $start = self::minutes((string) $config['start']);
        $end = self::minutes((string) $config['end']);

        if ($start === $end) {
            return false;
        }

        $local = ($at ?? now())->copy()->setTimezone($config['timezone'] ?? 'Asia/Jakarta');
        $now = $local->hour * 60 + $local->minute;

        return $start > $end
            ? ($now >= $start || $now < $end)
            : ($now >= $start && $now < $end);
    }

    /** "21:00 sampai 08:00 WIB" untuk pesan ke admin. */
    public static function label(): string
    {
        $config = config('marketing.promo_quiet_hours');
        $zone = match ($config['timezone'] ?? 'Asia/Jakarta') {
            'Asia/Jakarta' => 'WIB',
            'Asia/Makassar' => 'WITA',
            'Asia/Jayapura' => 'WIT',
            default => (string) $config['timezone'],
        };

        return "{$config['start']} sampai {$config['end']} {$zone}";
    }

    private static function minutes(string $time): int
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return ((int) $hour) * 60 + (int) $minute;
    }
}
