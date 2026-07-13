<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Role 'partner' sebelumnya cuma ditambahkan di RolePermissionSeeder —
     * tapi seeder TIDAK otomatis jalan pas `php artisan migrate` di
     * deploy production, jadi role-nya tidak pernah benar-benar ada di
     * DB sampai seeder dijalankan manual. Dipindah ke migration supaya
     * pasti ter-apply di setiap environment begitu migrate dijalankan.
     */
    public function up(): void
    {
        Role::findOrCreate('partner', 'web');
    }

    public function down(): void
    {
        // Sengaja tidak dihapus — kalau sudah ada partner yang pakai role
        // ini, menghapus role akan merusak data. Biarkan idle kalau di-rollback.
    }
};
