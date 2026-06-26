<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Role:
     * - super_admin     : akses penuh ke semua resource Filament.
     * - regional_admin  : "admin toko" — di-scope ke 1 store via users.store_id,
     *                      tidak punya akses ke Store & News (lihat Policies).
     */
    public function run(): void
    {
        $permissions = [
            'warranty.view',
            'warranty.manage',
            'quotation.view',
            'quotation.manage',
            'store.view',
            'store.manage',
            'news.view',
            'news.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $superAdmin = Role::findOrCreate('super_admin', 'web');
        $superAdmin->syncPermissions($permissions);

        $regionalAdmin = Role::findOrCreate('regional_admin', 'web');
        $regionalAdmin->syncPermissions([
            'warranty.view',
            'warranty.manage',
            'quotation.view',
            'quotation.manage',
            'store.view', // hanya lihat store miliknya sendiri, lihat StorePolicy
        ]);

        // User super_admin default — GANTI PASSWORD setelah login pertama kali.
        $admin = User::firstOrCreate(
            ['email' => 'admin@ginnva.test'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
            ]
        );
        $admin->syncRoles(['super_admin']);

        // Contoh user regional_admin (admin toko) — hanya dibuat kalau
        // sudah ada minimal 1 store di database (lihat StoreSeeder/data manual).
        $firstStore = Store::first();
        if ($firstStore) {
            $regional = User::firstOrCreate(
                ['email' => 'admin.toko@ginnva.test'],
                [
                    'name' => 'Admin Toko - '.$firstStore->name,
                    'password' => Hash::make('password'),
                    'store_id' => $firstStore->id,
                ]
            );
            $regional->syncRoles(['regional_admin']);
        }
    }
}
