<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerPointTransaction;
use App\Models\RewardRedemption;
use Illuminate\Http\Request;

class PartnerController extends Controller
{
    private function partnerOrAbort(Request $request): Partner
    {
        $partner = $request->user('api')->partner;

        abort_if(! $partner, 403, 'Akun ini bukan akun partner.');

        return $partner;
    }

    /**
     * GET /api/partner/me
     * Requires: auth:api, role partner
     */
    public function me(Request $request)
    {
        $partner = $this->partnerOrAbort($request);

        return response()->json([
            'success' => true,
            'data' => [
                'id'             => $partner->id,
                'business_name'  => $partner->business_name,
                'phone'          => $partner->phone,
                'referral_code'  => $partner->referral_code,
                'points_balance' => $partner->points_balance,
                'status'         => $partner->status,
            ],
        ]);
    }

    /**
     * GET /api/partner/points
     * Requires: auth:api, role partner
     */
    public function points(Request $request)
    {
        $partner = $this->partnerOrAbort($request);

        $transactions = PartnerPointTransaction::where('partner_id', $partner->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'success'      => true,
            'balance'      => $partner->points_balance,
            'transactions' => $transactions,
        ]);
    }

    /**
     * GET /api/partner/redemptions
     * Requires: auth:api, role partner
     */
    public function redemptions(Request $request)
    {
        $partner = $this->partnerOrAbort($request);

        $redemptions = RewardRedemption::with('reward')
            ->where('redeemer_type', 'partner')
            ->where('redeemer_id', $partner->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $redemptions]);
    }
}
