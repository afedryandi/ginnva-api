<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

/**
 * Override bawaan Laravel — project ini API-only, tidak ada route
 * bernama 'login'. Middleware default memanggil route('login') secara
 * EAGER di redirectTo() setiap kali guard gagal autentikasi (termasuk
 * saat request TIDAK kirim header Accept: application/json, mis. curl
 * polos/Postman tanpa header), dan karena route itu tidak ada, yang
 * terlempar malah RouteNotFoundException (500) — bukan response 401
 * JSON dari handler di bootstrap/app.php. Override ini memastikan tidak
 * pernah ada percobaan redirect sama sekali, apa pun header Accept-nya.
 */
class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
