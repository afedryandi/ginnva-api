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
        if (! $reward->isRedeemable()) {
            throw new RuntimeException('Reward ini sudah tidak tersedia.');
        }

        $balanceField = $redeemer instanceof Partner ? 'points_balance' : 'loyalty_points';
        $currentBalance = $redeemer->{$balanceField};

        if ($currentBalance < $reward->points_cost) {
            throw new RuntimeException('Poin Anda tidak cukup untuk menukar reward ini.');
        }

        return DB::transaction(function () use ($redeemer, $reward, $balanceField) {
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
