<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dipasang setelah auth:api. Token aplikasi berlaku 30 hari, jadi status akun harus dicek di setiap permintaan:
 * akun yang dinonaktifkan langsung ditolak (403), dan token yang terbit sebelum password direset admin / lupa password
 * ditolak (401) supaya aplikasi kembali ke layar login.
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');

        if ($user && $user->is_active === false) {
            return response()->json(['success' => false, 'code' => 'account_inactive', 'message' => 'Akun Anda sudah dinonaktifkan. Hubungi admin jika ini keliru.'], 403);
        }

        if ($user && $user->sessions_revoked_at) {
            $issuedAt = (int) auth('api')->payload()->get('iat');

            if ($issuedAt < $user->sessions_revoked_at->getTimestamp()) {
                return response()->json(['success' => false, 'code' => 'session_revoked', 'message' => 'Sesi berakhir karena password diubah. Silakan masuk lagi.'], 401);
            }
        }

        return $next($request);
    }
}
