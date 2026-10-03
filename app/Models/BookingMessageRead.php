<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Penanda "sudah dibaca" chat booking PER STAFF (keputusan user 2026-10-03):
 * badge pesan baru hilang hanya untuk staff yang membuka chat, tidak untuk
 * seluruh staff toko. Sisi customer cukup booking_messages.read_by_customer_at
 * (customer cuma satu orang per booking).
 */
class BookingMessageRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['booking_message_id', 'user_id', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];
}
