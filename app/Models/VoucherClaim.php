<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class VoucherClaim extends Model
{
    use LogsActivity;

    protected $fillable = [
        'voucher_id',
        'customer_id',
        'walkin_name',
        'walkin_phone',
        'reward_id',
        'reward_redemption_id',
        'discount_amount',
        'expires_at',
        'code',
        'status',
        'booking_id',
        'used_at',
    ];

    /**
     * Nama pemegang voucher untuk ditampilkan — akun app kalau ada
     * (customer_id terisi), atau nama walk-in yang dicatat manual staff
     * kalau tidak (customer belum/tidak install app).
     */
    public function getHolderNameAttribute(): ?string
    {
        return $this->customer?->name ?? $this->walkin_name;
    }

    protected $casts = [
        'used_at'         => 'datetime',
        'expires_at'      => 'date',
        'discount_amount' => 'decimal:2',
    ];

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    /** Nominal diskon voucher ini: snapshot saat diterbitkan, atau nominal kampanye untuk voucher fisik lama. */
    public function faceValue(): float
    {
        return (float) ($this->discount_amount ?? $this->voucher?->discount_amount ?? 0);
    }

    /** Nama voucher untuk ditampilkan (kampanye lama atau reward penerbit). */
    public function displayName(): string
    {
        return $this->voucher?->name ?? $this->reward?->name ?? 'Voucher';
    }

    /** Sudah lewat masa berlakunya (hari terakhir berlaku adalah expires_at itu sendiri). */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(today());
    }

    /**
     * Potongan yang BENAR-BENAR diberikan pada booking ini: snapshot Booking::voucher_discount (dicatat saat voucher
     * dipakai) kalau ada, jatuh ke nominal katalog Voucher::discount_amount untuk klaim lama yang belum punya snapshot.
     * Laporan memakai ini -- bukan katalog langsung -- supaya mengubah nominal voucher kemudian tidak menulis ulang
     * sejarah (invoice booking sudah memakai snapshot yang sama). Muat relasi booking & voucher dulu untuk daftar besar.
     */
    public function appliedDiscount(): float
    {
        return (float) ($this->booking?->voucher_discount ?? $this->faceValue());
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'status', 'booking_id', 'used_at', 'walkin_name', 'walkin_phone', 'discount_amount', 'expires_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('voucher_claim')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "Voucher {$this->code} diterbitkan ke customer",
                'updated' => "Voucher {$this->code} diubah",
                'deleted' => "Voucher {$this->code} dihapus",
                default   => "Voucher {$this->code} — {$eventName}",
            });
    }
}
