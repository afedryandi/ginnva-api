<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PartnershipInquiry extends Model
{
    // Audit trail (audit Kemitraan & Sales Referral) -- SEBELUMNYA perubahan status follow-up,
    // catatan internal sales, dan konversi jadi Partner tidak tercatat sama sekali, beda dari
    // ProductInquiry (fitur serupa) dan Partner (hasil konversinya) yang sudah punya ini.
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'notes', 'partner_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('partnership_inquiry');
    }

    protected $casts = [
        'contacted_at' => 'datetime',
        'outcome_at' => 'datetime',
    ];

    protected $fillable = [
        'customer_id',
        'category',
        'source',
        'applicant_name',
        'phone_number',
        'email',
        'city',
        'car_brand',
        'dealer_name',
        'message',
        'status',
        'notes',
        'partner_id',
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

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function owner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_user_id');
    }
}
