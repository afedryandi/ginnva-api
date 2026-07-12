<?php

namespace App\Observers;

use App\Models\PointTransaction;
use App\Models\ScrollCode;
use App\Models\Warranty;

class WarrantyObserver
{
    const POINTS_PER_APPROVAL = 100;

    /**
     * Tandai scroll code sebagai used saat warranty pertama kali dibuat.
     */
    public function created(Warranty $warranty): void
    {
        $this->markScrollCodesUsed($warranty);
    }

    /**
     * Saat warranty diupdate — cek apakah baru saja disetujui.
     */
    public function updated(Warranty $warranty): void
    {
        // Tandai scroll code baru kalau ada perubahan field roll_number
        if ($warranty->wasChanged(['roll_number', 'roll_number_front', 'roll_number_side_rear'])) {
            $this->markScrollCodesUsed($warranty);
        }

        // Hanya proses kalau review_status baru berubah menjadi 'approved'
        if (
            ! $warranty->wasChanged('review_status') ||
            $warranty->review_status !== 'approved' ||
            $warranty->customer_id === null
        ) {
            return;
        }

        $customer = $warranty->customer;
        if (! $customer) return;

        // Cegah duplikat — kalau sudah ada transaksi untuk warranty ini, skip
        $alreadyRewarded = PointTransaction::where('reference_type', 'warranty')
            ->where('reference_id', $warranty->id)
            ->exists();

        if ($alreadyRewarded) return;

        // Catat transaksi
        PointTransaction::create([
            'customer_id'    => $customer->id,
            'type'           => 'earn',
            'points'         => self::POINTS_PER_APPROVAL,
            'description'    => "Garansi {$warranty->warranty_code} disetujui",
            'reference_type' => 'warranty',
            'reference_id'   => $warranty->id,
        ]);

        // Update saldo di tabel customers (denormalized untuk performa)
        $customer->increment('loyalty_points', self::POINTS_PER_APPROVAL);
    }

    private function markScrollCodesUsed(Warranty $warranty): void
    {
        $codes = array_filter([
            $warranty->roll_number,
            $warranty->roll_number_front,
            $warranty->roll_number_side_rear,
        ]);

        if (empty($codes)) return;

        ScrollCode::whereIn('code', $codes)
            ->where('status', '!=', 'used')
            ->update([
                'status'       => 'used',
                'warranty_code' => $warranty->warranty_code,
                'used_at'      => now(),
            ]);
    }
}
