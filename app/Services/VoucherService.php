<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class VoucherService
{
    /**
     * Klaim voucher untuk 1 customer. Aman dari race condition (banyak
     * orang klaim bersamaan rebutan stok terbatas) — pakai row lock
     * (lockForUpdate) supaya cek-stok-lalu-kurangi jadi atomic, bukan
     * dua step terpisah yang bisa diselang proses lain.
     *
     * @throws RuntimeException kalau stok habis, voucher tidak aktif/
     *                           kedaluwarsa, atau customer sudah pernah klaim.
     */
    public function claim(Customer $customer, Voucher $voucher): VoucherClaim
    {
        return DB::transaction(function () use ($customer, $voucher) {
            /** @var Voucher $locked */
            $locked = Voucher::where('id', $voucher->id)->lockForUpdate()->first();

            if (! $locked->isClaimable()) {
                throw new RuntimeException('Voucher ini sudah tidak tersedia (stok habis atau sudah berakhir).');
            }

            $alreadyClaimed = VoucherClaim::where('voucher_id', $locked->id)
                ->where('customer_id', $customer->id)
                ->exists();

            if ($alreadyClaimed) {
                throw new RuntimeException('Anda sudah pernah klaim voucher ini.');
            }

            $claim = VoucherClaim::create([
                'voucher_id'  => $locked->id,
                'customer_id' => $customer->id,
                'code'        => VoucherClaim::generateCode(),
                'status'      => 'active',
            ]);

            $locked->increment('claimed_count');

            return $claim;
        });
    }

    /**
     * Pakai voucher saat booking selesai & customer bayar di toko —
     * dipanggil dari StaffBookingController::complete() bareng
     * ReferralPointService. Mengembalikan null kalau tidak ada kode
     * voucher yang diisi (opsional).
     *
     * @throws RuntimeException kalau kode voucher ada tapi tidak valid —
     *                           supaya staff tahu kenapa gagal, bukan
     *                           diam-diam diabaikan.
     */
    public function redeem(Booking $booking, ?string $code): ?VoucherClaim
    {
        if (empty($code)) {
            return null;
        }

        $claim = VoucherClaim::with('voucher')->where('code', strtoupper($code))->first();

        if (! $claim) {
            throw new RuntimeException('Kode voucher tidak ditemukan.');
        }

        if ($claim->status === 'used') {
            throw new RuntimeException('Voucher ini sudah pernah dipakai.');
        }

        if ($booking->customer_id && $claim->customer_id !== $booking->customer_id) {
            throw new RuntimeException('Kode voucher ini bukan milik customer booking ini.');
        }

        $claim->update([
            'status'     => 'used',
            'booking_id' => $booking->id,
            'used_at'    => now(),
        ]);

        return $claim;
    }
}
