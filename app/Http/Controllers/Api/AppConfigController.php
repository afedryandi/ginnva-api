<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/app/config — konfigurasi versi aplikasi mobile (publik). Aplikasi membacanya saat dibuka untuk menampilkan
 * layar "Perbarui aplikasi" (wajib) atau imbauan (opsional). Lihat config/app_version.php dan EnforceMinimumAppVersion.
 */
class AppConfigController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $platform = strtolower((string) ($request->header('X-App-Platform') ?: $request->query('platform', 'android')));
        if (! in_array($platform, ['android', 'ios'], true)) {
            $platform = 'android';
        }

        $config = config("app_version.{$platform}", []);
        $version = trim((string) ($request->header('X-App-Version') ?: $request->query('version', '')));
        $valid = (bool) preg_match('/^\d+(\.\d+){0,3}$/', $version);
        $min = $config['min'] ?? null;
        $latest = $config['latest'] ?? null;

        return response()->json([
            'success' => true,
            'data' => [
                'platform' => $platform,
                'min_version' => $min ?: null,
                'latest_version' => $latest ?: null,
                'store_url' => $config['store_url'] ?? null,
                'upgrade_required' => $valid && $min && version_compare($version, (string) $min, '<'),
                'update_available' => $valid && $latest && version_compare($version, (string) $latest, '<'),
            ],
        ]);
    }
}
