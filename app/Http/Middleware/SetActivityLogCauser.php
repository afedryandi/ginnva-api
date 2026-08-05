<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Activitylog\Facades\CauserResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * spatie/laravel-activitylog secara default cuma cek guard 'web' (default
 * guard Laravel) untuk tahu "siapa yang melakukan aksi ini". Endpoint
 * staff/partner di mobile app pakai guard 'api' (JWT), bukan sesi web —
 * tanpa middleware ini, semua aksi lewat mobile app (selesaikan booking,
 * redeem voucher, dst) akan tercatat tanpa causer sama sekali.
 */
class SetActivityLogCauser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');

        if ($user) {
            CauserResolver::setCauser($user);
        }

        return $next($request);
    }
}
