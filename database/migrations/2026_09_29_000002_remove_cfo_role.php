<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Keputusan user 2026-09-29: CEO, CCO, dan CFO setara sebagai 'direksi' --
 * tidak ada hak khusus CFO. Role 'cfo' (dibuat migrasi 2026_09_28_000010)
 * dibuang. Pengguna yang sempat diberi role 'cfo' DIPINDAHKAN ke 'direksi'
 * (bukan dihapus rolenya begitu saja), supaya tidak ada yang kehilangan
 * akses full-access. Idempotent: tidak berbuat apa-apa kalau role tidak ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $cfo = Role::where('name', 'cfo')->where('guard_name', 'web')->first();
        if (! $cfo) {
            return;
        }

        $direksi = Role::findOrCreate('direksi', 'web');

        User::role('cfo')->get()->each(function (User $user) use ($direksi) {
            $user->assignRole($direksi);
            $user->removeRole('cfo');
        });

        $cfo->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Sengaja no-op: role 'cfo' tidak dipulihkan (keputusan bisnis sudah dibatalkan).
    }
};
