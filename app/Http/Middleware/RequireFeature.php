<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak permintaan (404, seolah rutenya tidak ada) bila saklar fitur di config/features.php mati.
 * Dipakai sebagai ->middleware('feature:finance'). Dibaca saat permintaan masuk, bukan saat rute didaftarkan,
 * jadi aman dengan route:cache.
 */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless((bool) config("features.{$feature}"), 404);

        return $next($request);
    }
}
