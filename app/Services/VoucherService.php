<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Voucher fisik — dicetak dengan kode unik per lembar, dibagikan ke
 * customer yang sudah bayar DP. Tidak ada lagi klaim self-service dari
 * app (dulu ada, sudah dihapus) — staff yang input kode fisiknya lewat
 * Filament (assignToCustomer()), lalu otomatis tampil di "Voucher Saya"
 * customer terkait. Tidak terikat ke booking/discount sama sekali —
 * murni catatan "customer ini pernah dapat voucher fisik ini".
 */
class VoucherService
{
    /**
     * Assign 1 kode voucher fisik ke 1 customer. Dipanggil dari Filament
     * (VoucherResource\RelationManagers\ClaimsRelationManager) saat staff
     * input kode yang tertera di voucher fisik yang dipegang customer.
     *
     * @throws RuntimeException kalau stok voucher jenis ini sudah habis,
     *                           atau kode yang diinput sudah pernah
     *                           dipakai/di-assign sebelumnya (duplikat).
     */
    public function assignToCustomer(Voucher $voucher, string $code, int $customerId, ?int $bookingId = null): VoucherClaim
    {
        return $this->assign($voucher, $code, ['customer_id' => $customerId, 'booking_id' => $bookingId]);
    }

    /**
     * Assign 1 kode voucher fisik ke customer WALK-IN — belum/tidak
     * install mobile app, jadi tidak ada akun untuk ditempeli. Nama/HP
     * dicatat manual oleh staff sebagai pengganti akun (lihat
     * VoucherClaim::getHolderNameAttribute()).
     *
     * @throws RuntimeException sama seperti assignToCustomer().
     */
    public function assignToWalkin(Voucher $voucher, string $code, ?string $name, ?string $phone, ?int $bookingId = null): VoucherClaim
    {
        return $this->assign($voucher, $code, [
            'walkin_name'  => $name,
            'walkin_phone' => $phone,
            'booking_id'   => $bookingId,
        ]);
    }

    /**
     * Gap ditutup 2026-09-26 (audit Voucher Promo) -- SEBELUMNYA voucher
     * murni status tracking (ditandai "Terpakai" lewat menu Voucher),
     * tidak pernah benar-benar memotong nominal booking. Dipanggil dari
     * BookingResource::process_referral & TransactionApprovalService::approve()
     * (jalur full-access langsung MAUPUN jalur approval staff non-full-
     * access, supaya konsisten) -- WAJIB dalam DB::transaction() milik
     * pemanggil (booking juga di-lockForUpdate() di sana), lockForUpdate()
     * di sini mengunci baris claim itu sendiri.
     *
     * @throws RuntimeException kalau klaim sudah dipakai/tertaut booking
     *         LAIN sejak staff memilihnya di form (race condition 2 staff
     *         pilih kode yang sama nyaris bersamaan).
     */
    public function applyToBooking(int $voucherClaimId, Booking $booking): float
    {
        $claim = VoucherClaim::where('id', $voucherClaimId)->lockForUpdate()->first();

        if (! $claim) {
            throw new RuntimeException('Kode voucher tidak ditemukan.');
        }

        if ($claim->status !== 'active' && $claim->booking_id !== $booking->id) {
            throw new RuntimeException("Kode voucher {$claim->code} sudah dipakai/dipilih di transaksi lain.");
        }

        $claim->loadMissing('voucher:id,discount_amount');
        $discount = (float) ($claim->voucher?->discount_amount ?? 0);

        $claim->update([
            'status'     => 'used',
            'used_at'    => $claim->used_at ?? now(),
            'booking_id' => $booking->id,
        ]);

        return $discount;
    }

    /**
     * Lawan applyToBooking() -- dipanggil saat staff MELEPAS pilihan
     * voucher dari booking ini (ganti ke voucher lain, atau kosongkan).
     * Klaim dikembalikan jadi 'active' & lepas dari booking, supaya bisa
     * dipilih lagi di transaksi lain -- bukan hangus permanen cuma
     * karena sempat salah pilih.
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

    private function assign(Voucher $voucher, string $code, array $holderAttributes): VoucherClaim
    {
        return DB::transaction(function () use ($voucher, $code, $holderAttributes) {
            /** @var Voucher $locked */
            $locked = Voucher::where('id', $voucher->id)->lockForUpdate()->first();

            if (! $locked->isClaimable()) {
                throw new RuntimeException('Stok voucher jenis ini sudah habis atau sudah tidak aktif.');
            }

            $codeUpper = strtoupper(trim($code));

            $duplicate = VoucherClaim::whereRaw('UPPER(code) = ?', [$codeUpper])->exists();
            if ($duplicate) {
                throw new RuntimeException("Kode \"{$codeUpper}\" sudah pernah diinput sebelumnya.");
            }

            $claim = VoucherClaim::create(array_merge([
                'voucher_id' => $locked->id,
                'code'       => $codeUpper,
                'status'     => 'active',
            ], $holderAttributes));

            $locked->increment('claimed_count');

            return $claim;
        });
    }
}
