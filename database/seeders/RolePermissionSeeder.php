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
     * - super_admin     : "Direksi" — akses penuh ke semua resource Filament
     *                      + semua booking/chat di semua toko.
     * - regional_admin  : "Store Manager/Admin Toko" — di-scope ke 1 store via
     *                      users.store_id, tidak punya akses ke Store & News
     *                      (lihat Policies).
     * - installer       : "Tim Instalasi" — TIDAK punya akses Filament sama
     *                      sekali (login mobile app saja, guard 'api'). Hanya
     *                      bisa lihat & chat teks (bukan foto/update tahap) di
     *                      booking yang di-assign ke dirinya lewat kolom
     *                      bookings.installer_user_id.
     * - partner         : "Mitra Referral" — TIDAK punya akses Filament sama
     *                      sekali (login mobile app saja, guard 'api').
     *                      Profil poin & kode referralnya ada di App\Models\Partner
     *                      (relasi hasOne dari User), dibuat manual oleh admin
     *                      lewat PartnerResource di Filament.
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

        // installer TIDAK dikasih permission Filament apapun — dia cuma
        // login lewat mobile app (guard 'api'), tidak pernah masuk panel
        // admin web sama sekali.
        Role::findOrCreate('installer', 'web');

        // partner juga TIDAK dikasih permission Filament — sama seperti
        // installer, cuma login lewat mobile app.
        Role::findOrCreate('partner', 'web');

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

            // Contoh user installer — login mobile app saja, tidak pernah
            // dipakai untuk masuk Filament (lihat User::canAccessPanel()).
            $installer = User::firstOrCreate(
                ['email' => 'installer@ginnva.test'],
                [
                    'name' => 'Installer - '.$firstStore->name,
                    'password' => Hash::make('password'),
                    'store_id' => $firstStore->id,
                ]
            );
            $installer->syncRoles(['installer']);
        }
    }
}
