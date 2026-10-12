<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transport aman untuk API & panel admin (audit standar profesional 2026-10-12):
 * - Di production, permintaan HTTP biasa dialihkan ke HTTPS (308: metode & isi permintaan dipertahankan). Sebelumnya
 *   api.ginnva.id membalas 200 lewat HTTP polos, jadi token & password bisa terkirim tanpa enkripsi kalau ada klien
 *   yang salah memakai http://.
 * - Header keamanan standar di semua respons. HSTS hanya dikirim lewat koneksi HTTPS di production; tanpa
 *   includeSubDomains/preload supaya subdomain lain (mis. api-dev) tidak ikut terkunci.
 */
class SecureTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        $production = app()->environment('production');

        if ($production && ! $request->isSecure()) {
            return redirect()->secure($request->getRequestUri(), 308);
        }

        /** @var Response $response */
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'SAMEORIGIN',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
            'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=()',
        ];

        if ($production && $request->isSecure()) {
            // Mulai konservatif (1 minggu); naikkan ke 1 tahun setelah beberapa minggu tanpa masalah HTTPS.
            $headers['Strict-Transport-Security'] = 'max-age=604800';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
