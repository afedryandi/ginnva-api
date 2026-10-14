<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memaksa aplikasi mobile yang terlalu lama untuk diperbarui (Fase 0 pengerjaan ulang booking, 2026-10-14).
 *
 * Aplikasi mengirim X-App-Version (mis. "1.2.0") dan X-App-Platform ("android"/"ios") di setiap permintaan. Kalau
 * versinya di bawah config('app_version.<platform>.min') jawabannya 426 dengan pesan dan tautan toko aplikasi, supaya
 * perubahan kontrak API yang tidak kompatibel tidak diam-diam merusak aplikasi lama (seperti selfie wajib pada absen).
 *
 * Header kosong, platform tidak dikenal, atau versi tidak valid = LOLOS (klien lama dan ginnva-web tidak terpengaruh).
 * Endpoint api/app/config selalu lolos supaya aplikasi bisa membaca konfigurasi versinya.
 */
class EnforceMinimumAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $version = trim((string) $request->header('X-App-Version'));
        $platform = strtolower(trim((string) $request->header('X-App-Platform')));

        if ($version === '' || ! in_array($platform, ['android', 'ios'], true) || $request->is('api/app/config')) {
            return $next($request);
        }

        if (! preg_match('/^\d+(\.\d+){0,3}$/', $version)) {
            return $next($request);
        }

        $min = config("app_version.{$platform}.min");

        if ($min && version_compare($version, (string) $min, '<')) {
            return response()->json([
                'success' => false,
                'code' => 'upgrade_required',
                'message' => 'Versi aplikasi Anda sudah terlalu lama. Perbarui aplikasi ke versi terbaru untuk melanjutkan.',
                'min_version' => (string) $min,
                'store_url' => config("app_version.{$platform}.store_url"),
            ], 426);
        }

        return $next($request);
    }
}
