<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use App\Models\VoucherClaim;
use Illuminate\Http\Request;

/**
 * Voucher hasil tukar poin (keputusan 2026-10-10, menggantikan voucher fisik): staf membuat Reward bertipe Voucher di
 * menu Reward, customer menukar poin lewat /rewards/{id}/redeem, dan vouchernya langsung muncul di "Voucher Saya".
 * Endpoint di sini murni read-only.
 */
class VoucherController extends Controller
{
    /**
     * GET /api/customer/vouchers
     * Requires: auth:customer
     *
     * Voucher milik akun ini (hasil tukar poin, plus voucher fisik lama yang dulu di-assign staf). `status` apa adanya
     * di database (active/used); `effective_status` menambahkan "expired" untuk voucher aktif yang sudah lewat masa
     * berlakunya. Objek `voucher` dipertahankan untuk kompatibilitas aplikasi versi lama.
     */
    public function myVouchers(Request $request)
    {
        $customer = $request->user('customer');

        $claims = VoucherClaim::with(['voucher:id,name,description,discount_amount', 'reward:id,name,description'])
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (VoucherClaim $c) {
                $name = $c->displayName();
                $description = $c->voucher?->description ?? $c->reward?->description;
                $discount = $c->faceValue();

                return [
                    'id'               => $c->id,
                    'code'             => $c->code,
                    'status'           => $c->status,
                    'effective_status' => $c->status === 'active' && $c->isExpired() ? 'expired' : $c->status,
                    'name'             => $name,
                    'description'      => $description,
                    'discount_amount'  => $discount,
                    'expires_at'       => $c->expires_at?->format('Y-m-d'),
                    'voucher_id'       => $c->voucher_id,
                    'reward_id'        => $c->reward_id,
                    'created_at'       => $c->created_at,
                    'voucher'          => ['name' => $name, 'discount_amount' => $discount],
                ];
            });

        return response()->json(['success' => true, 'data' => $claims]);
    }

    /**
     * GET /api/customer/vouchers/available
     * Publik -- voucher yang bisa ditukar dengan poin (Reward bertipe Voucher yang aktif dan masih ada stok), untuk
     * halaman Promo. Hanya informasi; menukar lewat /rewards/{id}/redeem setelah login.
     */
    public function availableVouchers(Request $request)
    {
        $vouchers = Reward::where('is_active', true)
            ->where('type', Reward::TYPE_VOUCHER)
            ->where(fn ($q) => $q->whereNull('stock')->orWhere('stock', '>', 0))
            ->orderBy('points_cost')
            ->get()
            ->map(fn (Reward $r) => [
                'id'                 => $r->id,
                'reward_id'          => $r->id,
                'name'               => $r->name,
                'description'        => $r->description,
                'discount_amount'    => $r->voucher_discount,
                'points_cost'        => $r->points_cost,
                'voucher_valid_days' => $r->voucher_valid_days,
                'expires_at'         => null,
            ])
            ->values();

        return response()->json(['success' => true, 'data' => $vouchers]);
    }
}
