<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointTransaction;
use App\Models\RewardRedemption;

class PointController extends Controller
{
    /**
     * GET /api/customer/points
     * Requires: auth:customer
     */
    public function index()
    {
        $customer = auth('customer')->user();

        $transactions = PointTransaction::where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'balance'      => $customer->loyalty_points,
            'transactions' => $transactions,
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
