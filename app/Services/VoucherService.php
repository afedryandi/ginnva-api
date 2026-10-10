<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\VoucherClaim;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Voucher hasil tukar poin (keputusan 2026-10-10, menggantikan voucher fisik): staf membuat Reward bertipe Voucher,
 * customer menukar poin, lalu voucher langsung muncul di "Voucher Saya" dengan kode unik. Customer menunjukkan kode itu
 * saat booking berikutnya dan staf memilihnya di transaksi (applyToBooking()). Voucher melekat ke akun yang menukarnya.
 *
 * Klaim voucher fisik lama (voucher_id terisi, tanpa reward) tetap bisa dipakai lewat applyToBooking() yang sama.
 */
class VoucherService
{
    /**
     * Terbitkan voucher untuk satu penukaran poin. Dipanggil dari RewardRedemptionService::redeem() di dalam
     * transaksinya (reward & saldo sudah dikunci di sana).
     */
    public function issueForRedemption(RewardRedemption $redemption, Reward $reward, int $customerId): VoucherClaim
    {
        $validDays = (int) $reward->voucher_valid_days;

        return VoucherClaim::create([
            'reward_id'            => $reward->id,
            'reward_redemption_id' => $redemption->id,
            'customer_id'          => $customerId,
            'code'                 => $this->generateCode(),
            'status'               => 'active',
            // Nominal & masa berlaku di-snapshot: mengubah reward kemudian tidak menulis ulang voucher yang sudah terbit.
            'discount_amount'      => $reward->voucher_discount,
            'expires_at'           => $validDays > 0 ? today()->addDays($validDays)->toDateString() : null,
        ]);
    }

    /**
     * Pakai voucher pada booking: tandai terpakai & kembalikan nominal potongan. Dipanggil dari
     * BookingResource::process_referral & TransactionApprovalService::approve() -- WAJIB di dalam DB::transaction()
     * pemanggil (booking juga di-lockForUpdate() di sana); lockForUpdate() di sini mengunci baris klaim itu sendiri.
     *
     * @throws RuntimeException kalau voucher tidak ditemukan, sudah dipakai di transaksi lain, kedaluwarsa, atau milik
     *                           customer lain (voucher hasil tukar poin melekat ke akun penukarnya).
     */
    public function applyToBooking(int $voucherClaimId, Booking $booking): float
    {
        $claim = VoucherClaim::where('id', $voucherClaimId)->lockForUpdate()->first();

        if (! $claim) {
            throw new RuntimeException('Kode voucher tidak ditemukan.');
        }

        $alreadyOnThisBooking = $claim->booking_id === $booking->id;

        if ($claim->status !== 'active' && ! $alreadyOnThisBooking) {
            throw new RuntimeException("Kode voucher {$claim->code} sudah dipakai/dipilih di transaksi lain.");
        }

        if (! $alreadyOnThisBooking && $claim->isExpired()) {
            throw new RuntimeException("Voucher {$claim->code} sudah kedaluwarsa.");
        }

        if ($claim->reward_id && $claim->customer_id && $booking->customer_id && (int) $claim->customer_id !== (int) $booking->customer_id) {
            throw new RuntimeException("Voucher {$claim->code} milik customer lain dan tidak bisa dipakai di booking ini.");
        }

        $claim->loadMissing('voucher:id,discount_amount');
        $discount = $claim->faceValue();

        $claim->update([
            'status'     => 'used',
            'used_at'    => $claim->used_at ?? now(),
            'booking_id' => $booking->id,
        ]);

        return $discount;
    }

    /**
     * Lawan applyToBooking() -- dipanggil saat staf MELEPAS pilihan voucher dari booking ini (ganti ke voucher lain,
     * atau kosongkan). Klaim dikembalikan jadi 'active' & lepas dari booking, supaya bisa dipilih lagi -- bukan hangus
     * permanen cuma karena sempat salah pilih.
     */
    public function releaseFromBooking(int $voucherClaimId): void
    {
        $claim = VoucherClaim::where('id', $voucherClaimId)->lockForUpdate()->first();

        if (! $claim) {
            return;
        }

        $claim->update([
            'status'     => 'active',
            'used_at'    => null,
            'booking_id' => null,
        ]);
    }

    private function generateCode(): string
    {
        do {
            $code = 'GNV-' . Str::upper(Str::random(8));
        } while (VoucherClaim::whereRaw('UPPER(code) = ?', [$code])->exists());

        return $code;
    }
}
