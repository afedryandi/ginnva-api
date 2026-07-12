<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointTransaction;

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
}
