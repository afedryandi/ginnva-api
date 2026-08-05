<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Booking extends Model
{
    use LogsActivity;

    protected $fillable = [
        'booking_number',
        'customer_id',
        'customer_name',
        'phone_number',
        'store_id',
        'service_type',
        'product_kaca_film',
        'product_ppf',
        'preferred_date',
        'preferred_time',
        'notes',
        'source',
        'status',
        'current_stage',
        'secondary_stage',
        'next_service_reminder_at',
        'service_reminder_sent_at',
        'referral_code',
        'transaction_amount',
        'partner_id',
        'voucher_claim_id',
    ];

    protected $casts = [
        'preferred_date' => 'date',
        'transaction_amount' => 'decimal:2',
        'product_kaca_film' => 'boolean',
        'product_ppf' => 'boolean',
        'next_service_reminder_at' => 'date',
        'service_reminder_sent_at' => 'datetime',
    ];

    /**
     * Booking dengan 2 produk (Kaca Film + PPF) punya progress PARALEL —
     * Kaca Film selalu di `current_stage` (kolom lama, jadi single-product
     * booking tidak perlu berubah sama sekali), PPF di `secondary_stage`
     * KHUSUS saat dua-duanya dipesan. Tahap bersama (qc/completed) selalu
     * balik ke `current_stage` apa pun kondisi produknya.
     */
    public static function stageColumnFor(bool $hasBothProducts, string $stage): string
    {
        $isPpfStage = array_key_exists($stage, BookingMessage::PRODUCT_STAGES['ppf']);

        if ($hasBothProducts && $isPpfStage) {
            return 'secondary_stage';
        }

        return 'current_stage';
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Bisa lebih dari 1 installer per booking (mis. tim instalasi berdua)
     * — dulu cuma 1 lewat kolom installer_user_id, sekarang pivot
     * many-to-many sama seperti watchers().
     */
    public function installers()
    {
        return $this->belongsToMany(User::class, 'booking_installers')->withTimestamps();
    }

    /**
     * Direksi yang ditunjuk Store Manager untuk memantau booking ini secara
     * khusus — dipakai supaya notifikasi chat (push & email) tidak di-blast
     * ke SEMUA direksi, cukup ke yang memang ditugaskan (lihat
     * PushNotificationService::sendToBookingWatchers()).
     */
    public function watchers()
    {
        return $this->belongsToMany(User::class, 'booking_watchers')->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(BookingMessage::class)->orderBy('created_at');
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function voucherClaim()
    {
        return $this->belongsTo(VoucherClaim::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            if (empty($booking->booking_number)) {
                $booking->booking_number = static::generateBookingNumber();
            }
        });
    }

    protected static function generateBookingNumber(): string
    {
        do {
            $candidate = 'BKG-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (static::where('booking_number', $candidate)->exists());

        return $candidate;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'current_stage', 'secondary_stage', 'store_id', 'referral_code', 'transaction_amount', 'partner_id', 'voucher_claim_id', 'next_service_reminder_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('booking')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Booking #{$this->booking_number} dibuat",
                'updated' => "Booking #{$this->booking_number} diubah",
                'deleted' => "Booking #{$this->booking_number} dihapus",
                default   => "Booking #{$this->booking_number} — {$eventName}",
            });
    }
}
