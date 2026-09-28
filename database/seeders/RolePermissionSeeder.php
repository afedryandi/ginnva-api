<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Role:
     * - super_admin        : akses penuh ke semua resource Filament + semua
     *                         booking/chat di semua toko (lihat User::isFullAccess()).
     * - direksi            : setara super_admin — akses penuh, tidak dibatasi
     *                         menu_access sama sekali.
     * - marketing_manager,
     *   store_manager,
     *   developer,
     *   management_trainee,
     *   general_affair,
     *   ppf_leader,
     *   spv_finance        : role per-divisi — semuanya login Filament (guard
     *                         'web'), tapi akses menunya dibatasi lewat kolom
     *                         users.menu_access (centang "Akses Menu" di form
     *                         User), BUKAN dari role itu sendiri. Lihat
     *                         User::NO_PANEL_ROLES & hasMenuAccess().
     *
     *                         spv_finance ditambahkan supaya nama role di
     *                         sistem cocok dengan struktur organisasi di
     *                         "Pedoman & Tata Tertib GSI". Keputusan lama
     *                         "Finance tidak butuh akses Filament" DIBATALKAN
     *                         atas permintaan user — Spv Finance sekarang
     *                         perlu ikut chat booking (menggantikan Group
     *                         WhatsApp lama) meski tidak memegang menu
     *                         finansial apa pun di Filament (menu_access-nya
     *                         cukup dikosongkan/dibatasi sesuai kebutuhan).
     * - installer          : "Tim Instalasi" — TIDAK punya akses Filament sama
     *                         sekali (login mobile app saja, guard 'api'). Hanya
     *                         bisa lihat & chat teks (bukan foto/update tahap) di
     *                         booking yang dirinya termasuk salah satu installer
     *                         yang di-assign (Booking::installers(), bisa >1).
     * - partner            : "Mitra Referral" — TIDAK punya akses Filament sama
     *                         sekali (login mobile app saja, guard 'api').
     *                         Profil poin & kode referralnya ada di App\Models\Partner
     *                         (relasi hasOne dari User), dibuat manual oleh admin
     *                         lewat PartnerResource di Filament.
     *
     * SENGAJA tidak ada role Filament untuk Finance — divisi itu tidak
     * butuh akses admin panel (permintaan user).
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

        $direksi = Role::findOrCreate('direksi', 'web');
        $direksi->syncPermissions($permissions);

        $staffPermissions = [
            'warranty.view',
            'warranty.manage',
            'quotation.view',
            'quotation.manage',
            'store.view', // hanya lihat store miliknya sendiri, lihat StorePolicy
        ];

        // Role per-divisi — permission Spatie-nya sama (akses menu
        // sesungguhnya diatur lewat menu_access, bukan lewat permission
        // ini), cuma dipisah namanya supaya jabatan tercermin di sistem.
        // spv_finance ditambahkan supaya nama role cocok dengan struktur
        // organisasi di SOP.
        foreach ([
            'marketing_manager',
            'store_manager',
            'developer',
            'management_trainee',
            'general_affair',
            'ppf_leader',
            'spv_finance',
        ] as $divisionRole) {
            Role::findOrCreate($divisionRole, 'web')->syncPermissions($staffPermissions);
        }

        // installer TIDAK dikasih permission Filament apapun — dia cuma
        // login lewat mobile app (guard 'api'), tidak pernah masuk panel
        // admin web sama sekali.
        Role::findOrCreate('installer', 'web');

        // partner juga TIDAK dikasih permission Filament — sama seperti
        // installer, cuma login lewat mobile app.
        Role::findOrCreate('partner', 'web');

        // Password default "password" cuma dipakai di local/testing supaya
        // gampang dites. Di environment lain (staging/production) dibuatkan
        // password acak per akun dan di-print SEKALI ke console — mencegah
        // seeder ini membuat akun super_admin dengan password yang bisa
        // ditebak kalau tidak sengaja ter-run di server produksi.
        $isLocal = app()->environment(['local', 'testing']);
        $generatePassword = fn () => $isLocal ? 'password' : Str::random(20);

        // User super_admin default — GANTI PASSWORD setelah login pertama kali.
        $adminExists = User::where('email', 'admin@ginnva.test')->exists();
        $adminPassword = $generatePassword();
        $admin = User::firstOrCreate(
            ['email' => 'admin@ginnva.test'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make($adminPassword),
            ]
        );
        $admin->syncRoles(['super_admin']);
        if (! $adminExists && ! $isLocal) {
            $this->command?->warn("Akun super_admin dibuat: admin@ginnva.test / {$adminPassword} — segera login dan ganti password.");
        }

        // Contoh user store_manager (admin toko) — hanya dibuat kalau
        // sudah ada minimal 1 store di database (lihat StoreSeeder/data manual).
        $firstStore = Store::first();
        if ($firstStore) {
            $storeManagerExists = User::where('email', 'admin.toko@ginnva.test')->exists();
            $storeManagerPassword = $generatePassword();
            $storeManager = User::firstOrCreate(
                ['email' => 'admin.toko@ginnva.test'],
                [
                    'name' => 'Admin Toko - '.$firstStore->name,
                    'password' => Hash::make($storeManagerPassword),
                    'store_id' => $firstStore->id,
                ]
            );
            $storeManager->syncRoles(['store_manager']);
            if (! $storeManagerExists && ! $isLocal) {
                $this->command?->warn("Akun store_manager dibuat: admin.toko@ginnva.test / {$storeManagerPassword} — segera login dan ganti password.");
            }

            // Contoh user installer — login mobile app saja, tidak pernah
            // dipakai untuk masuk Filament (lihat User::canAccessPanel()).
            $installerExists = User::where('email', 'installer@ginnva.test')->exists();
            $installerPassword = $generatePassword();
            $installer = User::firstOrCreate(
                ['email' => 'installer@ginnva.test'],
                [
                    'name' => 'Installer - '.$firstStore->name,
                    'password' => Hash::make($installerPassword),
                    'store_id' => $firstStore->id,
                ]
            );
            $installer->syncRoles(['installer']);
            if (! $installerExists && ! $isLocal) {
                $this->command?->warn("Akun installer dibuat: installer@ginnva.test / {$installerPassword} — segera login dan ganti password.");
            }
        }
    }
}
