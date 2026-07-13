<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use RuntimeException;

class VoucherController extends Controller
{
    /**
     * GET /api/vouchers/active
     * Publik — dipakai buat nampilin banner "Klaim Voucher" di beranda,
     * termasuk sisa stok, sebelum user tahu apakah dirinya sudah login.
     */
    public function active(Request $request)
    {
        $voucher = Voucher::where('is_active', true)
            ->orderByDesc('created_at')
            ->get()
            ->first(fn (Voucher $v) => $v->isClaimable());

        if (! $voucher) {
            return response()->json(['success' => true, 'data' => null]);
        }

        $alreadyClaimed = false;
        $customer = auth('customer')->user();
        if ($customer) {
            $alreadyClaimed = VoucherClaim::where('voucher_id', $voucher->id)
                ->where('customer_id', $customer->id)
                ->exists();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id'               => $voucher->id,
                'name'             => $voucher->name,
                'description'      => $voucher->description,
                'discount_amount'  => $voucher->discount_amount,
                'remaining_stock'  => $voucher->remainingStock(),
                'total_stock'      => $voucher->total_stock,
                'already_claimed'  => $alreadyClaimed,
            ],
        ]);
    }

    /**
     * POST /api/customer/vouchers/{id}/claim
     * Requires: auth:customer
     */
    public function claim(Request $request, int $id, VoucherService $service)
    {
        $customer = $request->user('customer');
        $voucher = Voucher::findOrFail($id);

        try {
            $claim = $service->claim($customer, $voucher);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => $claim,
            'message' => "Voucher berhasil diklaim! Kode: {$claim->code}",
        ]);
    }

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
}
