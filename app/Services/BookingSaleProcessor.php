<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\SpendPromo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Memproses penjualan sebuah booking yang sudah selesai (Penjualan Fase 5, 2026-11-13): rincian harga, nominal, pembayaran yang diterima,
 * promo/voucher, kode referral, persetujuan, jurnal, dan poin referral. Diekstrak dari aksi "Proses Referral" di BookingResource supaya
 * panel dan aplikasi staf memakai SATU jalur dengan aturan yang sama (urutan dan pesan tidak berubah).
 *
 * Persetujuan (pemisahan tugas, audit 2026-09-14): staf non-akses-penuh tidak memposting langsung; permintaannya masuk antrean
 * persetujuan, kecuali nominal hanya didiskon sampai batas nego dari harga acuan (Booking::isWithinNegotiationCap).
 */
class BookingSaleProcessor
{
    /**
     * @param  array<string, mixed>  $input  transaction_amount, sale_lines, settlement_payments, referral_code, spend_promo_id,
     *                                       voucher_claim_id, vehicle_size (kunci ini boleh tidak ada)
     * @return array{status: 'posted'|'pending', messages: list<string>, message: string}
     *
     * @throws SaleProcessingException
     */
    public function process(Booking $record, array $input, User $actor): array
    {
        $lineService = app(BookingSaleLineService::class);
        $paymentService = app(BookingSettlementPaymentService::class);

        try {
            $saleLines = $lineService->normalize($input['sale_lines'] ?? []);
        } catch (RuntimeException $e) {
            throw new SaleProcessingException('Rincian harga belum benar', $e->getMessage());
        }

        $entered = (float) (($input['transaction_amount'] ?? '') !== '' ? $input['transaction_amount'] : 0);

        // Pembayaran yang diterima: diperiksa terhadap nominal sebelum apa pun disimpan.
        try {
            $payments = $paymentService->normalize($input['settlement_payments'] ?? [], $record->store_id ? (int) $record->store_id : null);
            $paymentService->assertWithinNominal($record, $payments, $entered);
        } catch (RuntimeException $e) {
            throw new SaleProcessingException('Pembayaran belum benar', $e->getMessage());
        }

        $amountReceived = $paymentService->amountReceived($record, $payments, $entered);
        $paymentMethod = BookingSettlementPaymentService::primaryMethod($payments);

        if ($saleLines === [] && $entered > 0 && BookingSaleLineService::isRequiredFor($record)) {
            throw new SaleProcessingException('Rincian harga wajib diisi', 'Isi minimal satu baris layanan dengan harganya sebelum menyimpan nominal transaksi.');
        }

        $voucherClaimId = ($input['voucher_claim_id'] ?? null) ?: null;

        // Validasi dan snapshot Promo Total Pembelian.
        $promoId = ($input['spend_promo_id'] ?? null) ?: null;
        $promoDiscount = null;
        if ($promoId) {
            $promo = SpendPromo::find($promoId);
            $promoDiscount = (float) ($promo->discount_amount ?? 0);
            $gross = $entered + $promoDiscount;
            if ($promo && $gross + 0.009 < (float) $promo->min_purchase_amount) {
                throw new SaleProcessingException(
                    'Promo tidak memenuhi syarat',
                    'Nominal kotor (Rp' . number_format($gross, 0, ',', '.') . ') belum mencapai minimal pembelian promo (Rp' . number_format((float) $promo->min_purchase_amount, 0, ',', '.') . ').'
                );
            }
        }

        $referralCode = ($input['referral_code'] ?? null) ?: null;
        $vehicleSize = array_key_exists('vehicle_size', $input) ? ($input['vehicle_size'] ?: null) : $record->vehicle_size;

        $referencePrice = $record->film_product_id ? PriceCalculator::priceFor($record->filmProduct, $vehicleSize) : null;
        $withinNegotiationCap = Booking::isWithinNegotiationCap($referencePrice, $entered);

        if (! $actor->isFullAccess() && ! $withinNegotiationCap) {
            try {
                app(TransactionApprovalService::class)->submitBookingReferral(
                    $record,
                    $entered,
                    $amountReceived,
                    [
                        'referral_code' => $referralCode,
                        'spend_promo_id' => $promoId,
                        'spend_promo_discount' => $promoId ? $promoDiscount : null,
                        // voucher_discount sengaja tidak dikirim: dihitung ulang dan klaimnya baru ditandai terpakai saat approve().
                        'voucher_claim_id' => $voucherClaimId,
                        'payment_method' => $paymentMethod,
                        'vehicle_size' => $vehicleSize,
                        'sale_lines' => $saleLines,
                        'settlement_payments' => $payments,
                    ],
                    $actor->id
                );
            } catch (RuntimeException $e) {
                throw new SaleProcessingException('Pengajuan tidak bisa dibuat', $e->getMessage());
            }

            $message = $referencePrice !== null
                ? 'Diskon melebihi ' . Booking::NEGOTIATION_DISCOUNT_MAX_PCT . '% dari harga acuan — permintaan ini dikirim ke admin/direksi untuk disetujui sebelum diposting ke Jurnal Umum.'
                : 'Permintaan Proses Referral untuk booking ini sudah dikirim ke admin/direksi untuk disetujui sebelum diposting ke Jurnal Umum.';

            return ['status' => 'pending', 'messages' => [], 'message' => $message];
        }

        // Nominal, rincian, pembayaran, dan jurnal pendapatan dalam SATU transaksi: kalau posting gagal (akun belum ada, periode ditutup),
        // nominalnya juga tidak tersimpan, jadi tidak ada nominal "yatim" tanpa jurnal.
        try {
            DB::transaction(function () use ($record, $input, $entered, $promoId, $promoDiscount, $voucherClaimId, $referralCode, $vehicleSize, $saleLines, $lineService, $payments, $paymentService, $amountReceived, $paymentMethod, $actor) {
                // Lepas klaim voucher lama bila dilepas/diganti.
                if ($record->voucher_claim_id && $record->voucher_claim_id !== $voucherClaimId) {
                    app(VoucherService::class)->releaseFromBooking($record->voucher_claim_id);
                }

                $voucherDiscount = $voucherClaimId ? app(VoucherService::class)->applyToBooking($voucherClaimId, $record) : null;

                $record->update([
                    'transaction_amount' => ($input['transaction_amount'] ?? '') !== '' ? $input['transaction_amount'] : null,
                    'amount_received' => $amountReceived,
                    'referral_code' => $referralCode,
                    'spend_promo_id' => $promoId,
                    'spend_promo_discount' => $promoId ? $promoDiscount : null,
                    'voucher_claim_id' => $voucherClaimId,
                    'voucher_discount' => $voucherClaimId ? $voucherDiscount : null,
                    'payment_method' => $paymentMethod,
                    'vehicle_size' => $vehicleSize,
                ]);

                // Rincian dan pembayaran disimpan SEBELUM jurnal pendapatan dibuat ulang, supaya pembagian per jenis dan baris kas memakainya.
                $lineService->replace($record, $saleLines);
                $paymentService->replace($record, $payments, $actor->id);

                app(BookingPostingService::class)->sync($record->refresh());
            });
        } catch (RuntimeException $e) {
            throw new SaleProcessingException('Nominal transaksi tidak bisa disimpan', $e->getMessage());
        }

        $messages = [];
        $points = app(ReferralPointService::class);

        // 1. Referral partner (bisnis mitra), dari kode yang diinput.
        if ($referralCode) {
            try {
                $partner = $points->awardForBooking($record->fresh());
                if ($partner) {
                    $messages[] = "Poin diberikan ke partner {$partner->business_name}.";
                }
            } catch (RuntimeException $e) {
                $messages[] = '⚠️ Partner: ' . $e->getMessage();
            }
        }

        // 2. Bonus "ajak teman" antar-customer, otomatis dari referred_by_customer_id di akun customer.
        try {
            $referrer = $points->awardForCustomerReferral($record->fresh());
            if ($referrer) {
                $messages[] = "Bonus ajak teman diberikan ke {$referrer->name}.";
            }
        } catch (RuntimeException $e) {
            $messages[] = '⚠️ Ajak Teman: ' . $e->getMessage();
        }

        return ['status' => 'posted', 'messages' => $messages, 'message' => $messages ? implode(' ', $messages) : 'Nominal transaksi disimpan.'];
    }
}
