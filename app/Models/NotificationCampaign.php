<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Satu kiriman dari menu Kirim Notifikasi (langsung atau terjadwal) beserta hasilnya. Lihat NotificationCampaignService. */
class NotificationCampaign extends Model
{
    use LogsActivity;

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

    /** Histori Aktivitas Fase 3: siapa membuat, menjadwalkan ulang, atau membatalkan kampanye, dan hasil akhirnya. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'audience', 'category', 'status', 'scheduled_at', 'message'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('notification_campaign')
            ->setDescriptionForEvent(fn (string $event) => match ($event) {
                'created' => "Kampanye notifikasi \"{$this->title}\" dibuat" . ($this->status === self::SCHEDULED ? ' (terjadwal)' : ''),
                'updated' => "Kampanye notifikasi \"{$this->title}\" diubah: " . (self::STATUS_LABELS[$this->status] ?? $this->status),
                'deleted' => "Kampanye notifikasi \"{$this->title}\" dihapus",
                default => "Kampanye notifikasi \"{$this->title}\" - {$event}",
            });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class, 'campaign_id');
    }
}
