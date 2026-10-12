<?php

namespace App\Http\Controllers;

/**
 * Link "Ajak Teman" -- /r/{code}. Dipakai untuk App Link Android (lihat android.intentFilters di app.json
 * ginnva-mobile). Kalau app SUDAH terinstall & tertaut sebagai verified App Link, Android mencegat URL ini SEBELUM
 * sampai ke browser -- halaman ini praktis hanya pernah dimuat kalau app BELUM terinstall, jadi tugasnya cuma satu:
 * redirect ke Play Store dengan parameter `referrer` supaya Install Referrer API tetap menitipkan kode itu (lihat
 * lib/referral-attribution.ts di mobile).
 *
 * Dipindah dari closure di routes/web.php ke controller (2026-10-12) supaya `php artisan route:cache` bisa dipakai
 * (route berbasis closure tidak bisa di-cache).
 */
class ReferralRedirectController extends Controller
{
    public function __invoke(string $code)
    {
        // Validasi longgar (bukan cek ke DB) -- sekadar mencegah karakter aneh ikut ke-passthrough ke parameter Play
        // Store. Kode yang benar-benar valid/tidaknya tetap divalidasi di AuthController::updateProfile() saat customer B
        // mengisi profil.
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

        $playStoreUrl = 'https://play.google.com/store/apps/details?id=id.ginnva.shield'
            . '&referrer=' . urlencode('ref=' . $code);

        return view('referral-redirect', ['playStoreUrl' => $playStoreUrl]);
    }
}
