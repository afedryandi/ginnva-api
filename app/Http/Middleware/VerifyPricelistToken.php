<?php

namespace App\Http\Middleware;

use App\Services\PricelistTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang auth khusus API Price List Kaca Film — sengaja terpisah total
 * dari middleware 'auth:api'/'auth:customer' milik sistem staff, karena
 * token di sini bukan Sanctum/JWT staff, cuma token HMAC stateless (lihat
 * PricelistTokenService). Allow-list Google Sheet hanya dicek sekali saat
 * login, bukan di setiap request — sesuai desain "sesi valid 8 jam".
 */
class VerifyPricelistToken
{
    public function __construct(private PricelistTokenService $tokens)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized();
        }

        $token = substr($header, 7);
        $email = $this->tokens->verify($token);

        if ($email === null) {
            return $this->unauthorized();
        }

        $request->attributes->set('pricelist_email', $email);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Sesi Anda sudah berakhir, silakan login ulang.',
        ], 401);
    }
}
