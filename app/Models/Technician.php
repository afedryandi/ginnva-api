<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Technician extends Model
{
    protected $fillable = [
        'store_id',
        'user_id',
        'name',
        'phone',
        'level',
        'status',
        'notes',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Akun installer (User ber-role 'installer') yang benar-benar
     * ditugaskan ke booking (lihat BookingResource "Installer Bertugas") —
     * opsional, supaya roster ini bisa ada duluan (mis. teknisi baru
     * direkrut) sebelum akun login-nya dibuat.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
