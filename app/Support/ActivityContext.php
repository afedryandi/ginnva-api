<?php

namespace App\Support;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Konteks setiap catatan Histori Aktivitas (Histori Aktivitas Fase 2, 2026-10-21): dari mana aksi datang dan, khusus pelaku staf,
 * alamat IP serta ringkasan perangkat. Disimpan di properties.context tanpa kolom baru. Keputusan pemilik 2026-10-21: IP dan
 * perangkat HANYA untuk staf; pelanggan hanya asal aksi (aplikasi pelanggan), tanpa IP.
 *
 * Dipasang sebagai hook `creating` pada model Activity (AppServiceProvider). Kegagalan mencatat konteks tidak boleh menggagalkan
 * aksi utama, jadi seluruhnya dibungkus try/catch.
 */
class ActivityContext
{
    public const SOURCE_LABELS = [
        'panel' => 'Panel Admin',
        'mobile_staf' => 'Aplikasi Staff',
        'mobile_pelanggan' => 'Aplikasi Pelanggan',
        'api' => 'API',
        'web' => 'Web',
        'konsol' => 'Sistem (terjadwal/konsol)',
    ];

    public static function stamp(Activity $activity): void
    {
        try {
            $properties = $activity->properties?->toArray() ?? [];

            if (isset($properties['context'])) {
                return;
            }

            $context = ['source' => self::source()];

            // 'audience' => 'staff' adalah petunjuk internal dari AccountSecurityLog untuk percobaan masuk gagal yang pelakunya belum
            // dikenali; tidak ikut disimpan.
            $staffActor = $activity->causer_type === User::class || ($properties['audience'] ?? null) === 'staff';
            unset($properties['audience']);

            $request = self::httpRequest();

            if ($staffActor && $request) {
                $context['ip'] = $request->ip();
                $context['device'] = Str::limit((string) $request->userAgent(), 200, '');
            }

            $properties['context'] = $context;
            $activity->properties = $properties;
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Asal aksi: aplikasi staf, aplikasi pelanggan, API lain, panel admin, web, atau konsol (perintah terjadwal, tinker). */
    public static function source(): string
    {
        $request = self::httpRequest();

        if (! $request) {
            return 'konsol';
        }

        $path = $request->path();

        return match (true) {
            str_starts_with($path, 'api/staff') => 'mobile_staf',
            str_starts_with($path, 'api/customer') => 'mobile_pelanggan',
            str_starts_with($path, 'api/') => 'api',
            Filament::getCurrentPanel() !== null => 'panel',
            default => 'web',
        };
    }

    /** Permintaan HTTP yang sungguh ada (punya rute); null di konsol dan perintah terjadwal. */
    private static function httpRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        return $request->route() !== null ? $request : null;
    }
}
