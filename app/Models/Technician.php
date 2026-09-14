<?php

namespace App\Models;

use App\Models\Concerns\HasStoreScope;
use Illuminate\Database\Eloquent\Model;

class Technician extends Model
{
    // Global Scope store-id (audit framework 2026-09-14, "Isolasi data
    // multi-tenant") — lihat App\Models\Scopes\StoreScope. Pola manual
    // yang sudah ada di TechnicianResource::getEloquentQuery() SENGAJA
    // DIBIARKAN sebagai defense-in-depth.
    use HasStoreScope;

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
