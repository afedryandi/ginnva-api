<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
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
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
    ];

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
