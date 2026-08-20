<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements FilamentUser, JWTSubject
{
    use HasRoles;
    use LogsActivity;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'store_id',
        'menu_access',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'menu_access' => 'array',
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
     * Role yang TIDAK PERNAH boleh masuk Filament panel — login mobile app
     * saja (guard 'api'). Kebalikan dari pendekatan "daftar role yang
     * boleh": role staff/divisi baru yang dibuat admin sendiri lewat
     * RoleResource otomatis kebagian akses panel TANPA perlu ada yang
     * update array PHP ini setiap kali ada divisi baru — cuma installer &
     * partner yang memang secara desain tidak pernah pakai Filament sama
     * sekali, dua-duanya sudah final & tidak akan nambah.
     */
    public const NO_PANEL_ROLES = ['installer', 'partner'];

    /**
     * super_admin & direksi = akses penuh, tidak pernah dibatasi menu_access.
     */
    public function isFullAccess(): bool
    {
        return $this->hasAnyRole(['super_admin', 'direksi']);
    }

    /**
     * Boleh masuk ke Filament panel sama sekali — TIDAK berarti boleh lihat
     * semua menu (itu diatur per-resource lewat hasMenuAccess()). Berlaku
     * untuk role APA PUN selain yang ada di NO_PANEL_ROLES, termasuk role
     * baru yang dibuat admin sendiri lewat RoleResource — supaya tidak
     * perlu edit kode tiap kali ada divisi baru.
     */
    public function canAccessStaffArea(): bool
    {
        return $this->roles->pluck('name')->diff(self::NO_PANEL_ROLES)->isNotEmpty();
    }

    /**
     * Role staff "biasa" (bukan full-access, bukan installer/partner) —
     * dipakai untuk hal-hal yang relevan buat staff toko/kantor: label
     * chat, fan-out notifikasi ke staff toko, dsb. Termasuk role baru yang
     * dibuat lewat RoleResource secara otomatis.
     */
    public function isRestrictedStaff(): bool
    {
        return $this->canAccessStaffArea() && ! $this->isFullAccess();
    }

    /**
     * Gate masuk ke Filament admin panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->canAccessStaffArea();
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function partner()
    {
        return $this->hasOne(Partner::class);
    }

    /**
     * Label yang ditampilkan di chat booking — SENGAJA bukan nama asli
     * staff (privasi/profesionalitas), tapi berdasarkan jabatan. Supaya
     * tidak N+1, panggil method ini setelah eager-load 'store:id,name'
     * pada query yang memuat user ini (lihat BookingMessageController).
     */
    public function chatDisplayLabel(): string
    {
        if ($this->isFullAccess()) {
            return 'Ginnva Management';
        }

        if ($this->hasRole('installer')) {
            return 'Tim Instalasi';
        }

        if ($this->isRestrictedStaff()) {
            return $this->store ? "Admin Toko {$this->store->name}" : 'Admin Toko';
        }

        return 'Tim Ginnva';
    }

    public function getDefaultGuardName(): string
    {
        return 'web';
    }

    /**
     * Dipanggil dari canViewAny() tiap Filament Resource untuk cek "apakah
     * user ini boleh lihat menu X" — di atas pengecekan role yang sudah ada.
     *
     * - super_admin/direksi (isFullAccess()): SELALU true, tidak pernah
     *   dibatasi field menu_access.
     * - menu_access NULL (belum pernah diatur admin): SELALU true — supaya
     *   akun staff yang sudah ada sebelum fitur ini tidak mendadak
     *   kehilangan akses ke menu yang biasa mereka pakai.
     * - menu_access array (sudah diatur eksplisit, termasuk array kosong):
     *   true HANYA kalau nama resource ini ($resourceClass, boleh nama
     *   class penuh atau basename-nya) ada di daftar.
     */
    public function hasMenuAccess(string $resourceClass): bool
    {
        if ($this->isFullAccess()) {
            return true;
        }

        if ($this->menu_access === null) {
            return true;
        }

        $basename = class_basename($resourceClass);

        return in_array($resourceClass, $this->menu_access, true)
            || in_array($basename, $this->menu_access, true);
    }

    /**
     * Dipakai app mobile staff untuk memutuskan halaman awal setelah
     * login (lihat AuthController::transform()) — installer SELALU true
     * di sini walau canAccessStaffArea()-nya false (installer tidak
     * punya akses panel Filament sama sekali), karena mereka tetap perlu
     * lihat booking yang di-assign ke diri sendiri lewat mobile app.
     */
    public function hasBookingAccess(): bool
    {
        if ($this->hasRole('installer')) {
            return true;
        }

        return $this->canAccessStaffArea()
            && $this->hasMenuAccess(\App\Filament\Resources\BookingResource::class);
    }

    /**
     * Sama tujuannya dengan hasBookingAccess() — true kalau akun ini
     * dicentang akses ke SALAH SATU menu inventaris (Barang/Bahan
     * Baku/Aset) di Filament. Dipakai buat putuskan apakah staff diarahkan
     * ke hub Inventaris sama sekali; untuk filter menu MANA yang
     * ditampilkan di dalam hub itu, pakai 3 method granular di bawah.
     */
    public function hasInventoryAccess(): bool
    {
        return $this->hasPpfWfAccess() || $this->hasMaterialAccess() || $this->hasAssetAccess() || $this->hasConsumableAccess();
    }

    public function hasPpfWfAccess(): bool
    {
        return $this->canAccessStaffArea()
            && $this->hasMenuAccess(\App\Filament\Resources\InventoryItemResource::class);
    }

    public function hasMaterialAccess(): bool
    {
        return $this->canAccessStaffArea()
            && $this->hasMenuAccess(\App\Filament\Resources\RawMaterialResource::class);
    }

    public function hasAssetAccess(): bool
    {
        return $this->canAccessStaffArea()
            && $this->hasMenuAccess(\App\Filament\Resources\AssetResource::class);
    }

    public function hasConsumableAccess(): bool
    {
        return $this->canAccessStaffArea()
            && $this->hasMenuAccess(\App\Filament\Resources\ConsumableItemResource::class);
    }

    public function hasMaterialMemoAccess(): bool
    {
        return $this->canAccessStaffArea()
            && $this->hasMenuAccess(\App\Filament\Resources\MaterialMemoResource::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        // SENGAJA tidak masukkan 'password' walau di-hash — jangan pernah
        // simpan representasi password di log manapun. 'menu_access' SENGAJA
        // dimasukkan — ini perubahan hak akses, salah satu hal paling
        // sensitif yang perlu diaudit (siapa mengubah akses menu siapa).
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'store_id', 'menu_access'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('user')
            ->setDescriptionForEvent(fn (string $eventName) => match ($eventName) {
                'created' => "User \"{$this->name}\" ({$this->email}) dibuat",
                'updated' => "User \"{$this->name}\" ({$this->email}) diubah",
                'deleted' => "User \"{$this->name}\" ({$this->email}) dihapus",
                default   => "User \"{$this->name}\" — {$eventName}",
            });
    }
}
