<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesPublicFileUrl;
use App\Http\Controllers\Controller;
use App\Models\Reward;
use App\Services\RewardRedemptionService;
use Illuminate\Http\Request;
use RuntimeException;

class RewardController extends Controller
{
    use ResolvesPublicFileUrl;

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

        return response()->json([
            'success' => true,
            'data' => $rewards->map(fn (Reward $r) => [
                'id'              => $r->id,
                'name'            => $r->name,
                'description'     => $r->description,
                // Kolom `image` di DB cuma path relatif (disk 'public',
                // mis. "rewards/abc123.png") — wajib di-convert jadi URL
                // lengkap di sini karena mobile app langsung pakai field
                // ini sebagai URI. SEBELUMNYA pakai asset('storage/...')
                // manual — bekerja untuk disk lokal, tapi menyimpang dari
                // ResolvesPublicFileUrl (satu-satunya sumber kebenaran
                // yang sudah dipakai NewsController/CaseStudyController/
                // MaterialController) yang juga aman kalau disk-nya
                // pindah ke S3/CDN di masa depan (Storage::url() sudah
                // full URL, tidak di-double-prefix).
                'image'           => $this->fullImageUrl($r->image),
                'points_cost'     => $r->points_cost,
                'stock'           => $r->stock,
            ]),
        ]);
    }

    /**
     * POST /api/partner/rewards/{id}/redeem
     * Requires: auth:api, role partner
     */
    public function redeemAsPartner(Request $request, int $id, RewardRedemptionService $service)
    {
        $partner = $request->user('api')->partner;
        abort_if(! $partner, 403, 'Akun ini bukan akun partner.');
        // Partner nonaktif tidak boleh spend poin walau tokennya masih
        // valid — sama seperti gate di PartnerController::partnerOrAbort().
        abort_if($partner->status !== 'active', 403, 'Akun partner ini sedang nonaktif.');

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
