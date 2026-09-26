<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
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

    // LogsActivity ditambahkan 2026-09-26 (audit Daftar Pelanggan) --
    // SEBELUMNYA tidak ada sama sekali, padahal 2 jalur admin (action
    // "Data Pribadi" di CustomerResource ubah gender/address, dan action
    // "Direferensikan Partner"/"Ajak Teman" ubah referred_by_*) adalah
    // SATU-SATUNYA cara field ini diubah manual staff -- tanpa ini,
    // perubahan PII customer oleh staff sama sekali tidak terlacak siapa/
    // kapan. Pola sama dengan gap yang ditemukan & diperbaiki di audit
    // "audit trail sweep" sebelumnya (Technician, BankStatementLine).
    use LogsActivity;

    // Audit framework 2026-09-14, "Penanganan data pribadi (PII)
    // pelanggan" -- kolom deleted_at SUDAH ADA sejak migrasi
    // add_deleted_at_to_customers_table, tapi trait ini SEBELUMNYA
    // TIDAK pernah dipasang, jadi deleted_at tertulis tapi tidak
    // fungsional sama sekali (tidak ada query yang otomatis
    // mengecualikan akun yang sudah dihapus). Relasi lain (Booking/
    // Warranty ->customer) tetap aman dipakai untuk tampilan historis
    // karena semuanya sudah punya fallback ke kolom customer_name/
    // phone_number yang didenormalisasi, bukan bergantung ke relasi
    // ini untuk data yang sudah dianonimkan.
    use SoftDeletes;

    public const GENDER_LABELS = [
        'male' => 'Laki-Laki',
        'female' => 'Perempuan',
    ];

    protected $fillable = [
        'name',
        'email',
        'phone_number',
        // Grup Pelanggan (audit Majoo f40) — opsional, dipakai untuk
        // harga khusus per grup (lihat FilmProductGroupPrice).
        'customer_group_id',
        'gender',
        'address',
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

    public function customerGroup()
    {
        return $this->belongsTo(CustomerGroup::class);
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'phone_number', 'gender', 'address', 'customer_group_id', 'referred_by_customer_id', 'referred_by_partner_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('customer')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Pelanggan \"{$this->name}\" terdaftar",
                'updated' => "Data pelanggan \"{$this->name}\" diubah",
                'deleted' => "Akun pelanggan \"{$this->name}\" dihapus",
                default   => "Pelanggan \"{$this->name}\" — {$eventName}",
            });
    }
}
