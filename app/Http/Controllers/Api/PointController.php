<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointTransaction;
use App\Models\RewardRedemption;
use Illuminate\Http\Request;

class PointController extends Controller
{
    /**
     * GET /api/customer/points?page=1
     * Requires: auth:customer
     *
     * Bug diperbaiki 2026-09-26 (audit Riwayat Poin Customer) --
     * SEBELUMNYA ->limit(50) hardcoded tanpa pagination sama sekali,
     * customer dengan riwayat >50 baris (realistis untuk customer lama
     * dengan banyak booking+referral+redeem) kehilangan transaksi lama
     * dari tampilan TANPA cara apa pun untuk melihatnya lagi. Sekarang
     * pakai paginate() -- 'transactions' TETAP array polos (backward
     * compatible dengan mobile app versi lama yang belum baca 'has_more'),
     * ditambah 'has_more'/'current_page' supaya mobile app BISA
     * di-upgrade konsumsi load-more-nya belakangan tanpa breaking change.
     */
    public function index(Request $request)
    {
        $customer = auth('customer')->user();

        $paginated = PointTransaction::where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->paginate(50, ['*'], 'page', (int) $request->query('page', 1));

        return response()->json([
            'success'      => true,
            'balance'      => $customer->loyalty_points,
            'transactions' => $paginated->items(),
            'current_page' => $paginated->currentPage(),
            'has_more'     => $paginated->hasMorePages(),
            'total'        => $paginated->total(),
        ]);
    }

    /**
     * GET /api/customer/redemptions
     * Requires: auth:customer
     */
    public function redemptions()
    {
        $customer = auth('customer')->user();

        $redemptions = RewardRedemption::with('reward')
            ->where('redeemer_type', 'customer')
            ->where('redeemer_id', $customer->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $redemptions]);
    }
}
