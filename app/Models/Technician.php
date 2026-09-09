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
        // Nominal komisi TETAP per pekerjaan/booking untuk teknisi ini --
        // NULL = belum diatur (bukan Rp 0). Lihat migrasi
        // 2026_09_08_000002 & Laporan Komisi Teknisi.
        'commission_amount',
        'status',
        'notes',
    ];

    protected $casts = [
        'commission_amount' => 'decimal:2',
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
