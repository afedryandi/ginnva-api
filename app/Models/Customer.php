<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * Akun end-customer untuk mobile app — TERPISAH dari App\Models\User
 * (akun admin Filament). Memakai guard JWT sendiri ('customer'), supaya
 * token customer tidak bisa dipakai untuk akses endpoint admin manapun,
 * dan sebaliknya.
 */
class Customer extends Model implements Authenticatable, JWTSubject
{
    use AuthenticatableTrait;

    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'email_verified_at',
        'phone_verified_at',
        'referral_code',
        'referred_by_customer_id',
        'referred_by_partner_id',
        'loyalty_points',
        'deleted_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'deleted_at'         => 'datetime',
        'loyalty_points'     => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Customer $customer) {
            if (empty($customer->referral_code)) {
                $customer->referral_code = self::generateReferralCode();
            }
        });
    }

    /**
     * Generate kode referral unik untuk customer — dipakai buat "ajak
     * teman" (beda dari referral Partner). Format lebih pendek (6 karakter)
     * daripada punya Partner (8) karena ini dibagikan casual ke teman,
     * bukan dicetak di materi promosi resmi.
     */
    public static function generateReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (self::where('referral_code', $code)->exists());

        return $code;
    }

    public function referredBy()
    {
        return $this->belongsTo(Customer::class, 'referred_by_customer_id');
    }

    public function referrals()
    {
        return $this->hasMany(Customer::class, 'referred_by_customer_id');
    }

    /**
     * Partner yang MEREFERENSIKAN customer ini — penanda manual dari
     * admin (lihat migrasi 2026_07_25_000001), bukan sumber poin
     * otomatis. Poin tetap diproses lewat Booking::referral_code saat
     * booking selesai (ReferralPointService::awardForBooking()).
     */
    public function referredByPartner()
    {
        return $this->belongsTo(Partner::class, 'referred_by_partner_id');
    }

    public function warranties()
    {
        return $this->hasMany(Warranty::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function redemptions()
    {
        return RewardRedemption::where('redeemer_type', 'customer')->where('redeemer_id', $this->id);
    }

    public function voucherClaims()
    {
        return $this->hasMany(VoucherClaim::class);
    }

    public function galleryPhotos()
    {
        return $this->hasMany(CustomerGalleryPhoto::class);
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        // Claim 'guard' disertakan supaya kalau suatu saat token ini
        // ter-decode di tempat yang salah, jelas terlihat ini token
        // customer, bukan admin — memudahkan debugging & logging.
        return ['guard' => 'customer'];
    }
}
