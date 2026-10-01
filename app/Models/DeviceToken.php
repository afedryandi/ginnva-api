<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceToken extends Model
{
    protected $fillable = ['customer_id', 'user_id', 'token', 'platform', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];

    /**
     * Satu device_token HARUS cuma punya SATU identitas aktif pada satu
     * waktu — customer_id ATAU user_id, tidak boleh keduanya (diperbaiki
     * 2026-10-01, audit Notifikasi). Skema tidak punya constraint untuk
     * ini, jadi dijaga di sini sebagai defense-in-depth: controller
     * (NotificationController::linkToken()/linkTokenStaff()) sudah
     * null-kan field lawan secara eksplisit juga, tapi kalau ada
     * penulisan lain ke tabel ini di masa depan yang lupa melakukannya,
     * guard ini tetap mencegah 1 device menerima push milik 2 akun
     * sekaligus.
     */
    protected static function booted(): void
    {
        static::saving(function (DeviceToken $deviceToken) {
            if ($deviceToken->isDirty('customer_id') && $deviceToken->customer_id) {
                $deviceToken->user_id = null;
            } elseif ($deviceToken->isDirty('user_id') && $deviceToken->user_id) {
                $deviceToken->customer_id = null;
            }
        });
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
