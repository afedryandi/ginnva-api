<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Penanda "partner X sudah baca notifikasi broadcast Y" — lihat catatan
 * di migration 2026_07_22_000012_create_partner_notification_reads_table.
 */
class PartnerNotificationRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['partner_id', 'partner_notification_id', 'read_at'];

    protected $casts = [
        'read_at' => 'datetime',
    ];
}
