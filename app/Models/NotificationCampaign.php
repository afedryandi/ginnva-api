<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu kiriman dari menu Kirim Notifikasi (langsung atau terjadwal) beserta hasilnya. Lihat NotificationCampaignService. */
class NotificationCampaign extends Model
{
    public const SCHEDULED = 'scheduled';
    public const SENDING = 'sending';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::SCHEDULED => 'Terjadwal',
        self::SENDING => 'Sedang dikirim',
        self::SENT => 'Terkirim',
        self::FAILED => 'Gagal',
        self::CANCELLED => 'Dibatalkan',
    ];

    protected $fillable = [
        'audience', 'category', 'title', 'body', 'data', 'customer_ids', 'partner_ids', 'status', 'scheduled_at', 'sent_at',
        'recipients', 'skipped', 'devices_sent', 'devices_failed', 'campaign_key', 'message', 'created_by',
    ];

    protected $casts = [
        'data' => 'array',
        'customer_ids' => 'array',
        'partner_ids' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'campaign_id');
    }
}
