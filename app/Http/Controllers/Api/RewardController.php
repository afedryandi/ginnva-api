<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use App\Services\RewardRedemptionService;
use Illuminate\Http\Request;
use RuntimeException;

class RewardController extends Controller
{
    /**
     * GET /api/rewards
     * Katalog reward — sama untuk partner maupun customer, publik (tidak
     * perlu login untuk lihat-lihat, cuma redeem yang butuh auth).
     */
    public function index()
    {
        $rewards = Reward::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('stock')->orWhere('stock', '>', 0))
            ->orderBy('points_cost')
            ->get();

        return response()->json(['success' => true, 'data' => $rewards]);
    }

    /**
     * POST /api/partner/rewards/{id}/redeem
     * Requires: auth:api, role partner
     */
    public function redeemAsPartner(Request $request, int $id, RewardRedemptionService $service)
    {
        $partner = $request->user('api')->partner;
        abort_if(! $partner, 403, 'Akun ini bukan akun partner.');

        return $this->handleRedeem($service, $partner, $id);
    }

    /**
     * POST /api/customer/rewards/{id}/redeem
     * Requires: auth:customer
     */
    public function redeemAsCustomer(Request $request, int $id, RewardRedemptionService $service)
    {
        $customer = $request->user('customer');

        return $this->handleRedeem($service, $customer, $id);
    }

    private function handleRedeem(RewardRedemptionService $service, $redeemer, int $rewardId)
    {
        $reward = Reward::findOrFail($rewardId);

        try {
            $redemption = $service->redeem($redeemer, $reward);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => $redemption,
            'message' => 'Reward berhasil ditukar. Tim kami akan menghubungi Anda untuk proses selanjutnya.',
        ]);
    }
}
