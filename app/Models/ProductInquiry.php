<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ProductInquiry extends Model
{
    use HasFactory;

    // Audit trail (audit Inquiry Produk 2026-09-29) -- SEBELUMNYA perubahan status follow-up
    // dan catatan internal sales tidak tercatat sama sekali.
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'notes'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('product_inquiry');
    }

    protected $casts = [
        'contacted_at' => 'datetime',
        'outcome_at' => 'datetime',
    ];

    protected $fillable = [
        'inquiry_number',
        'customer_name',
        'customer_contact',
        'message',
        'status',
        'notes',
        'interest',
        'created_by_user_id',
        'channel',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'click_id',
        'landing_path',
        'referrer',
        'owner_user_id',
        'outcome',
        'outcome_reason',
        'outcome_at',
        'contacted_at',
    ];

    /**
     * Generate inquiry_number otomatis saat record dibuat,
     * mengikuti format yang sama dengan quotation_number:
     * INQ-YYYYMM-XXXX (4 karakter random alfanumerik uppercase)
     *
     * Catatan: prefix sengaja dibedakan jadi "AVL" (availability)
     * supaya tim sales bisa langsung membedakan ini bukan quotation
     * beli, melainkan inquiry ketersediaan produk.
     */
    protected static function booted(): void
    {
        static::creating(function (ProductInquiry $inquiry) {
            if (empty($inquiry->inquiry_number)) {
                $inquiry->inquiry_number = static::generateInquiryNumber();
            }
        });
    }

    protected static function generateInquiryNumber(): string
    {
        do {
            $candidate = 'AVL-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (static::where('inquiry_number', $candidate)->exists());

        return $candidate;
    }

    public function owner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_user_id');
    }
}
