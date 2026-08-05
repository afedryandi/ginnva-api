<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Penanda "customer X sudah baca notifikasi broadcast Y" — lihat catatan
 * di migration 2026_07_17_000001_create_customer_notification_reads_table
 * untuk alasan kenapa broadcast tidak bisa pakai customer_notifications.read_at
 * langsung (1 baris dibagi semua customer).
 */
class CustomerNotificationRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['customer_id', 'customer_notification_id', 'read_at'];

    protected $casts = [
        'read_at' => 'datetime',
    ];
}
