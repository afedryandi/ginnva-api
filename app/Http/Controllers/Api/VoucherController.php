<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use Illuminate\Http\Request;

/**
 * Voucher sekarang FISIK (dicetak dengan kode unik masing-masing, dibagikan
 * ke customer yang sudah bayar DP) — staff meng-assign kode voucher fisik
 * ke akun customer lewat Filament (lihat VoucherResource\RelationManagers\
 * ClaimsRelationManager). Customer TIDAK bisa klaim sendiri lewat app lagi
 * (endpoint browse/claim yang lama sudah dihapus), cuma bisa lihat daftar
 * voucher yang sudah di-assign ke akunnya di sini.
 */
class VoucherController extends Controller
{
    /**
     * GET /api/customer/vouchers
     * Requires: auth:customer
     */
    public function myVouchers(Request $request)
    {
        $customer = $request->user('customer');

        $claims = VoucherClaim::with('voucher')
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $claims]);
    }

    /**
     * GET /api/customer/vouchers/available
     * Publik — SENGAJA di luar auth:customer, supaya customer yang belum
     * login pun bisa lihat promo yang berjalan dan terdorong datang ke
     * toko (lihat banner ajakan di app/account/promo.tsx).
     *
     * Untuk section "Promo" di beranda/menu — daftar KAMPANYE voucher yang
     * masih berjalan (informasi/marketing), TERPISAH dari "Voucher Saya"
     * (voucher fisik yang sudah di-assign ke akun ini). Sengaja tetap
     * tampil di sini walaupun customer sudah punya voucher itu — bukan
     * untuk klaim, cuma supaya customer tahu promo apa saja yang sedang
     * berjalan.
     */
    public function availableVouchers(Request $request)
    {
        $vouchers = Voucher::where('is_active', true)
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (Voucher $v) => ! $v->expires_at || $v->expires_at->isFuture())
            // Voucher kuota terbatas (mis. "200 pembeli pertama") yang
            // kuotanya sudah habis TIDAK boleh ikut tampil sebagai promo
            // "sedang berjalan" — tanpa filter ini, customer bisa
            // terdorong datang ke toko untuk promo yang sebenarnya sudah
            // habis, karena cuma dicek is_active & expiry, bukan stok.
            ->filter(fn (Voucher $v) => ! $v->total_stock || $v->claimed_count < $v->total_stock)
            ->values();

        return response()->json(['success' => true, 'data' => $vouchers]);
    }
}
