<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\PartnerPointTransaction;
use App\Models\PointTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReferralPointService
{
    /**
     * Rasio konversi poin — 1 poin per Rp 10.000 transaksi, sama untuk
     * partner maupun customer. Default sementara (belum ada aturan bisnis
     * resmi) — ubah angka ini kalau rasionya berubah, dipakai di satu
     * tempat saja supaya gampang di-maintain.
     */
    private const RUPIAH_PER_POINT = 10000;

    /**
     * Dipanggil saat staff toko menandai booking selesai + input kode
     * referral & nominal transaksi. Kasih poin ke partner (pemilik kode)
     * DAN ke customer booking tersebut, dalam 1 DB transaction supaya
     * tidak ada poin yang "nyangkut" kalau salah satu gagal.
     *
     * Aman dipanggil berkali-kali untuk booking yang sama — hanya
     * memproses sekali (idempotent, cek reference_type+reference_id).
     *
     * @throws RuntimeException kalau kode referral DIISI tapi tidak valid,
     *                           akun partner nonaktif, atau nominal
     *                           transaksi kosong/nol — supaya staff tahu
     *                           kenapa poin tidak keluar, bukan diam-diam
     *                           gagal tanpa penjelasan.
     */
    public function awardForBooking(Booking $booking): ?Partner
    {
        if (empty($booking->referral_code)) {
            return null;
        }

        if (empty($booking->transaction_amount) || $booking->transaction_amount <= 0) {
            throw new RuntimeException('Kode referral diisi tapi Nominal Transaksi kosong/nol — poin tidak bisa dihitung. Isi nominal transaksi untuk memberikan poin.');
        }

        $partner = Partner::whereRaw('UPPER(referral_code) = ?', [strtoupper($booking->referral_code)])
            ->first();

        if (! $partner) {
            throw new RuntimeException("Kode referral \"{$booking->referral_code}\" tidak ditemukan.");
        }

        if ($partner->status !== 'active') {
            throw new RuntimeException("Partner \"{$partner->business_name}\" sedang nonaktif, poin tidak diberikan.");
        }

        $points = (int) floor($booking->transaction_amount / self::RUPIAH_PER_POINT);

        if ($points < 1) {
            throw new RuntimeException('Nominal transaksi terlalu kecil untuk menghasilkan poin (minimal Rp10.000).');
        }

        return DB::transaction(function () use ($booking, $partner, $points) {
            // Lock baris partner SEBELUM cek idempotency — sebelumnya
            // pengecekan "sudah pernah diproses atau belum" dilakukan TANPA
            // lock & di LUAR transaction, jadi 2 klik tombol "Proses
            // Referral" yang hampir bersamaan (dobel klik staff, atau 2
            // tab admin) bisa dua-duanya lolos cek sebelum salah satu
            // commit, memberi poin dobel ke partner & customer yang sama.
            $lockedPartner = Partner::where('id', $partner->id)->lockForUpdate()->first();

            // Idempotency guard — cek apakah booking ini sudah pernah
            // diproses. PENTING: dicek juga partner-nya SAMA atau BEDA dari
            // sebelumnya. Kalau cuma cek "sudah pernah diproses" tanpa
            // peduli partner mana, staff yang mengoreksi kode referral yang
            // salah (Partner A -> B) akan lihat pesan "Poin diberikan ke
            // Partner B" padahal tidak ada poin baru yang diberikan sama
            // sekali — Partner A tetap memegang poin yang salah, tanpa
            // staff sadar butuh koreksi manual.
            $existingTransaction = PartnerPointTransaction::where('reference_type', 'booking')
                ->where('reference_id', $booking->id)
                ->first();

            if ($existingTransaction) {
                if ((int) $existingTransaction->partner_id === $lockedPartner->id) {
                    return $lockedPartner;
                }

                throw new RuntimeException(
                    "Booking ini sudah diproses sebelumnya untuk partner lain (kode referral berbeda). Poin TIDAK dipindahkan otomatis ke \"{$lockedPartner->business_name}\" — hubungi admin untuk koreksi manual kalau memang salah input."
                );
            }

            // Poin untuk partner
            PartnerPointTransaction::create([
                'partner_id'     => $lockedPartner->id,
                'type'           => 'earn',
                'points'         => $points,
                'description'    => "Referral booking #{$booking->booking_number}",
                'reference_type' => 'booking',
                'reference_id'   => $booking->id,
            ]);
            $lockedPartner->increment('points_balance', $points);
            $booking->partner_id = $lockedPartner->id;
            $booking->saveQuietly();

            // Poin untuk customer (kalau booking ini terikat akun customer —
            // booking manual/walk-in dari staff tidak selalu punya customer_id)
            if ($booking->customer_id) {
                PointTransaction::create([
                    'customer_id'    => $booking->customer_id,
                    'type'           => 'earn',
                    'points'         => $points,
                    'description'    => "Booking #{$booking->booking_number} (kode referral {$lockedPartner->referral_code})",
                    'reference_type' => 'booking',
                    'reference_id'   => $booking->id,
                ]);
                $booking->customer()->increment('loyalty_points', $points);
            }

            return $lockedPartner;
        });
    }

    /**
     * Bonus "ajak teman" ANTAR-CUSTOMER — beda dari awardForBooking() di
     * atas (yang soal Partner bisnis). Kalau customer pemilik booking ini
     * dulu daftar pakai kode referral customer LAIN (referred_by_customer_id,
     * diisi sekali saat Complete Profile), pengaju kode itu dapat poin
     * yang SAMA nilainya (rasio & rumus sama persis) setiap booking milik
     * customer yang diajaknya selesai dengan nominal transaksi terisi.
     *
     * Dipanggil bareng-bareng dengan awardForBooking() dari aksi "Proses
     * Referral" di Filament — independen satu sama lain, booking bisa
     * punya salah satu, keduanya, atau tidak dua-duanya.
     */
    public function awardForCustomerReferral(Booking $booking): ?Customer
    {
        if (! $booking->customer_id) {
            return null;
        }

        $customer = $booking->customer;

        if (! $customer || ! $customer->referred_by_customer_id) {
            return null;
        }

        if (empty($booking->transaction_amount) || $booking->transaction_amount <= 0) {
            throw new RuntimeException('Customer ini diajak lewat kode referral teman, tapi Nominal Transaksi kosong/nol — poin bonus tidak bisa dihitung.');
        }

        $referrer = $customer->referredBy;

        if (! $referrer) {
            return null;
        }

        $points = (int) floor($booking->transaction_amount / self::RUPIAH_PER_POINT);

        if ($points < 1) {
            return null;
        }

        return DB::transaction(function () use ($booking, $customer, $referrer, $points) {
            // Lock baris referrer SEBELUM cek idempotency — sama alasan
            // dengan awardForBooking() di atas.
            $lockedReferrer = Customer::where('id', $referrer->id)->lockForUpdate()->first();
            if (! $lockedReferrer) return null;

            $alreadyProcessed = PointTransaction::where('reference_type', 'customer_referral')
                ->where('reference_id', $booking->id)
                ->exists();

            if ($alreadyProcessed) {
                return $lockedReferrer;
            }

            PointTransaction::create([
                'customer_id'    => $lockedReferrer->id,
                'type'           => 'earn',
                'points'         => $points,
                'description'    => "Bonus ajak teman — {$customer->name} booking #{$booking->booking_number}",
                'reference_type' => 'customer_referral',
                'reference_id'   => $booking->id,
            ]);
            $lockedReferrer->increment('loyalty_points', $points);

            return $lockedReferrer;
        });
    }
}
