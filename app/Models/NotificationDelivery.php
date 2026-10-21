<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Catatan pengiriman push per token (Notifikasi Fase 3). Lihat migrasi create_notification_deliveries_table. */
class NotificationDelivery extends Model
{
    public const ACCEPTED = 'accepted';
    public const DELIVERED = 'delivered';
    public const FAILED = 'failed';
    public const DEAD_TOKEN = 'dead_token';
    public const UNKNOWN = 'unknown';

    public const STATUS_LABELS = [
        self::ACCEPTED => 'Menunggu kuitansi',
        self::DELIVERED => 'Terkirim',
        self::FAILED => 'Gagal',
        self::DEAD_TOKEN => 'Token mati',
        self::UNKNOWN => 'Tidak diketahui',
    ];

    public $timestamps = false;

    protected $fillable = ['user_id', 'customer_id', 'campaign_id', 'token', 'title', 'ticket_id', 'status', 'error', 'sent_at', 'checked_at'];

    protected $casts = ['sent_at' => 'datetime', 'checked_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
