<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\WarrantyMaintenanceVisit;
use Illuminate\Support\Facades\DB;

/**
 * Poin otomatis untuk customer setiap kunjungan maintenance selesai (2026-10-10). Besarannya dari
 * config('loyalty.maintenance_visit_points'); 0 berarti nonaktif.
 *
 * - Satu kunjungan = paling banyak satu entri poin (dijaga reference_type + reference_id di bawah row lock customer).
 * - Kunjungan yang dibatalkan (salah catat) menarik kembali poinnya. Kalau customer sudah memakai sebagian poin itu,
 *   yang ditarik hanya sebatas saldo yang masih ada (saldo tidak pernah jadi negatif).
 */
class MaintenanceVisitPointService
{
    public const REFERENCE_EARN = 'maintenance_visit';
    public const REFERENCE_REVERSAL = 'maintenance_visit_reversal';

    public function award(WarrantyMaintenanceVisit $visit): void
    {
        $points = (int) config('loyalty.maintenance_visit_points', 0);
        $visit->loadMissing('warranty');
        $customerId = $visit->warranty?->customer_id;

        if ($points <= 0 || ! $customerId || $visit->isCancelled()) {
            return;
        }

        DB::transaction(function () use ($visit, $points, $customerId) {
            $customer = Customer::where('id', $customerId)->lockForUpdate()->first();

            if (! $customer) {
                return;
            }

            $already = PointTransaction::where('reference_type', self::REFERENCE_EARN)
                ->where('reference_id', $visit->id)
                ->exists();

            if ($already) {
                return;
            }

            PointTransaction::create([
                'customer_id'    => $customer->id,
                'type'           => 'earn',
                'points'         => $points,
                'description'    => "Kunjungan maintenance garansi {$visit->warranty->warranty_code}",
                'reference_type' => self::REFERENCE_EARN,
                'reference_id'   => $visit->id,
            ]);

            $customer->increment('loyalty_points', $points);
        });
    }

    public function revoke(WarrantyMaintenanceVisit $visit): void
    {
        $visit->loadMissing('warranty');
        $customerId = $visit->warranty?->customer_id;

        if (! $customerId) {
            return;
        }

        DB::transaction(function () use ($visit, $customerId) {
            $customer = Customer::withTrashed()->where('id', $customerId)->lockForUpdate()->first();

            if (! $customer) {
                return;
            }

            $earned = PointTransaction::where('reference_type', self::REFERENCE_EARN)
                ->where('reference_id', $visit->id)
                ->first();

            $alreadyReversed = PointTransaction::where('reference_type', self::REFERENCE_REVERSAL)
                ->where('reference_id', $visit->id)
                ->exists();

            if (! $earned || $alreadyReversed) {
                return;
            }

            $reversible = min((int) $earned->points, max(0, (int) $customer->loyalty_points));

            if ($reversible <= 0) {
                return;
            }

            PointTransaction::create([
                'customer_id'    => $customer->id,
                'type'           => 'spend',
                'points'         => $reversible,
                'description'    => "Kunjungan maintenance dibatalkan (garansi {$visit->warranty->warranty_code})",
                'reference_type' => self::REFERENCE_REVERSAL,
                'reference_id'   => $visit->id,
            ]);

            $customer->decrement('loyalty_points', $reversible);
        });
    }
}
