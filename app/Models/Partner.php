<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Partner extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'business_name',
        'phone',
        'referral_code',
        'status',
        'source',
        'type',
        'points_balance',
    ];

    protected $casts = [
        'points_balance' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PartnerPointTransaction::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Generate kode referral unik — dipakai saat partner baru dibuat di
     * Filament. Format: 8 karakter huruf besar+angka, mudah diucapkan/
     * diketik manual oleh partner ke kenalannya.
     */
    public static function generateReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (self::where('referral_code', $code)->exists());

        return $code;
    }

    /**
     * Bikin akun login (User, role 'partner') + profil Partner sekaligus.
     * Dipakai bersama oleh PartnerResource\Pages\CreatePartner (bikin
     * partner langsung) dan PartnershipInquiryResource (konversi dari
     * pengajuan kemitraan yang sudah deal) — supaya logikanya satu tempat.
     */
    public static function createAccount(array $data): self
    {
        $user = User::create([
            'name'     => $data['business_name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        $user->syncRoles(['partner']);

        return self::create([
            'user_id'       => $user->id,
            'business_name' => $data['business_name'],
            'phone'         => $data['phone'] ?? null,
            'status'        => $data['status'] ?? 'active',
            // Nullable dengan sengaja kalau tidak dikirim (mis. dibuat
            // manual admin lewat PartnerResource\Pages\CreatePartner
            // tanpa isi field ini) — beda dari 2 controller signup
            // (Giias/PartnerSignupController) yang SELALU isi eksplisit.
            'source'        => $data['source'] ?? null,
            'type'          => $data['type'] ?? 'partner',
            'referral_code' => self::generateReferralCode(),
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['business_name', 'phone', 'status', 'source', 'type', 'points_balance'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('partner')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Partner \"{$this->business_name}\" dibuat",
                'updated' => "Partner \"{$this->business_name}\" diubah",
                'deleted' => "Partner \"{$this->business_name}\" dihapus",
                default   => "Partner \"{$this->business_name}\" — {$eventName}",
            });
    }
}
