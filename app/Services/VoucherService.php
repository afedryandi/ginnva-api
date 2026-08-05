<?php

namespace App\Services;

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
