<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements FilamentUser, JWTSubject
{
    use HasRoles;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'store_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    /**
     * Gate masuk ke Filament admin panel.
     * Hanya user dengan role super_admin / regional_admin yang boleh login.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole(['super_admin', 'regional_admin']);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Label yang ditampilkan di chat booking — SENGAJA bukan nama asli
     * staff (privasi/profesionalitas), tapi berdasarkan jabatan. Supaya
     * tidak N+1, panggil method ini setelah eager-load 'store:id,name'
     * pada query yang memuat user ini (lihat BookingMessageController).
     */
    public function chatDisplayLabel(): string
    {
        if ($this->hasRole('super_admin')) {
            return 'Ginnva Management';
        }

        if ($this->hasRole('installer')) {
            return 'Tim Instalasi';
        }

        if ($this->hasRole('regional_admin')) {
            return $this->store ? "Admin Toko {$this->store->name}" : 'Admin Toko';
        }

        return 'Tim Ginnva';
    }

    public function getDefaultGuardName(): string
    {
        return 'web';
    }
}
