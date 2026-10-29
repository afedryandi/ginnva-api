<?php

namespace App\Filament\Resources\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Gerbang akses standar resource modul Karyawan (K3 & Kinerja, Karyawan Fase 5): terlihat bagi staf yang diberi akses menu, dan
 * buat/ubah/hapus mengikuti izin aksi modul. Staf non-akses-penuh hanya melihat data tokonya sendiri.
 */
trait HasKaryawanAccess
{
    /**
     * Saklar fitur (config/features.php) yang harus menyala agar resource ini terbuka, atau null bila selalu terbuka.
     * Resource menimpanya, mis. 'k3', 'performance_reviews', 'employee_training'.
     */
    protected static function requiredFeature(): ?string
    {
        return null;
    }

    public static function canViewAny(): bool
    {
        $feature = static::requiredFeature();

        if ($feature !== null && ! config("features.{$feature}")) {
            return false;
        }

        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'create', true) ?? false);
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'update', true) ?? false);
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny() && (auth()->user()?->hasModuleAction(static::class, 'delete', true) ?? false);
    }

    /** Batasi ke toko staf yang login (kecuali akses penuh). */
    protected static function limitToOwnStore(Builder $query): Builder
    {
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    /** Daftar karyawan aktif (selain mitra) yang boleh dipilih; staf toko hanya karyawan tokonya. */
    protected static function employeeOptions(): array
    {
        $user = auth()->user();

        return \App\Models\User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
            ->when($user && ! $user->isFullAccess(), fn ($q) => $q->where('store_id', $user->store_id))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
