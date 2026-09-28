<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role 'cfo' (keputusan user 2026-09-28, mengacu ke struktur organisasi):
 * full-access seperti 'direksi' PLUS pemegang persetujuan keuangan
 * (User::isFinanceApprover()). Izin (permission) disalin dari role
 * 'direksi' kalau ada. Idempotent -- findOrCreate, tidak menimpa apa pun.
 * Setelah migrasi: beri role 'cfo' ke akun Ibu Yennie lewat menu User.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $cfo = Role::findOrCreate('cfo', 'web');

        $direksi = Role::where('name', 'direksi')->where('guard_name', 'web')->first();
        if ($direksi) {
            $cfo->syncPermissions($direksi->permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::where('name', 'cfo')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
