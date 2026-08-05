<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Partner;
use App\Models\PartnerPointTransaction;
use App\Models\PointTransaction;
use App\Models\Reward;
use App\Models\RewardRedemption;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RewardRedemptionService
{
    /**
     * Tukar poin partner ATAU customer dengan reward — dipakai bersama
     * oleh kedua sisi supaya logika kurangi saldo & catat histori tidak
     * dobel ditulis.
     *
     * @throws RuntimeException kalau saldo poin tidak cukup atau reward
     *                           tidak tersedia (supaya controller tinggal
     *                           tangkap pesan-nya untuk response error).
     */
    public function redeem(Partner|Customer $redeemer, Reward $reward): RewardRedemption
    {
        return DB::transaction(function () use ($redeemer, $reward) {
            // Lock baris reward & redeemer SELAMA transaction supaya 2
            // redeem bersamaan (mis. reward stok terbatas saat traffic
            // tinggi) tidak lolos double-check-then-write dan bikin stok
            // atau saldo poin minus — pola sama seperti VoucherService::assign().
            $lockedReward = Reward::where('id', $reward->id)->lockForUpdate()->first();

            if (! $lockedReward->isRedeemable()) {
                throw new RuntimeException('Reward ini sudah tidak tersedia.');
            }

            $balanceField = $redeemer instanceof Partner ? 'points_balance' : 'loyalty_points';

            $lockedRedeemer = $redeemer->newQuery()->where('id', $redeemer->id)->lockForUpdate()->first();
            $currentBalance = $lockedRedeemer->{$balanceField};

            if ($currentBalance < $lockedReward->points_cost) {
                throw new RuntimeException('Poin Anda tidak cukup untuk menukar reward ini.');
            }

            $reward = $lockedReward;
            $redeemer = $lockedRedeemer;

            $redeemerType = $redeemer instanceof Partner ? 'partner' : 'customer';

            $redemption = RewardRedemption::create([
                'redeemer_type' => $redeemerType,
                'redeemer_id'   => $redeemer->id,
                'reward_id'     => $reward->id,
                'points_spent'  => $reward->points_cost,
                'status'        => 'pending',
            ]);

            $redeemer->decrement($balanceField, $reward->points_cost);

            if ($reward->stock !== null) {
                $reward->decrement('stock');
            }

            if ($redeemerType === 'partner') {
                PartnerPointTransaction::create([
                    'partner_id'     => $redeemer->id,
                    'type'           => 'spend',
                    'points'         => $reward->points_cost,
                    'description'    => "Tukar reward: {$reward->name}",
                    'reference_type' => 'reward_redemption',
                    'reference_id'   => $redemption->id,
                ]);
            } else {
                PointTransaction::create([
                    'customer_id'    => $redeemer->id,
                    'type'           => 'spend',
                    'points'         => $reward->points_cost,
                    'description'    => "Tukar reward: {$reward->name}",
                    'reference_type' => 'reward_redemption',
                    'reference_id'   => $redemption->id,
                ]);
            }

            return $redemption;
        });
    }
}
