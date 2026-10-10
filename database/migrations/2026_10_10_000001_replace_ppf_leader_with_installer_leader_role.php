<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Role "ppf_leader" dihapus dan diganti "installer_leader" (keputusan 2026-10-10): leader installer ikut chat booking
 * bersama customer dan tim Ginnva. Akun yang sudah memakai ppf_leader otomatis pindah ke installer_leader.
 *
 * - Hanya ppf_leader yang ada: role DIGANTI NAMA (id sama, jadi penugasan user dan permission-nya utuh).
 * - Keduanya sudah ada (mis. seeder sudah membuat installer_leader): penugasan dipindah ke installer_leader lalu
 *   ppf_leader dihapus. Aman diulang.
 *
 * Tidak reversibel penuh: down() hanya mengganti nama kembali bila hanya installer_leader yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $old = DB::table('roles')->where('name', 'ppf_leader')->where('guard_name', 'web')->first();

        if (! $old) {
            return;
        }

        $new = DB::table('roles')->where('name', 'installer_leader')->where('guard_name', 'web')->first();

        if (! $new) {
            DB::table('roles')->where('id', $old->id)->update(['name' => 'installer_leader']);
        } else {
            // Pindahkan penugasan user (hindari duplikat), lalu buang role lama beserta relasinya.
            $existing = DB::table('model_has_roles')->where('role_id', $new->id)->get()
                ->map(fn ($r) => $r->model_type . ':' . $r->model_id)->all();

            foreach (DB::table('model_has_roles')->where('role_id', $old->id)->get() as $assignment) {
                if (! in_array($assignment->model_type . ':' . $assignment->model_id, $existing, true)) {
                    DB::table('model_has_roles')
                        ->where('role_id', $old->id)->where('model_type', $assignment->model_type)->where('model_id', $assignment->model_id)
                        ->update(['role_id' => $new->id]);
                }
            }

            DB::table('model_has_roles')->where('role_id', $old->id)->delete();
            DB::table('role_has_permissions')->where('role_id', $old->id)->delete();
            DB::table('roles')->where('id', $old->id)->delete();
        }

        // Cache permission Spatie harus dibuang supaya perubahan nama role langsung terbaca.
        try {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Throwable $e) {
            // Cache belum tersedia saat migrasi dijalankan sangat awal: abaikan.
        }
    }

    public function down(): void
    {
        $hasOld = DB::table('roles')->where('name', 'ppf_leader')->where('guard_name', 'web')->exists();
        $new = DB::table('roles')->where('name', 'installer_leader')->where('guard_name', 'web')->first();

        if (! $hasOld && $new) {
            DB::table('roles')->where('id', $new->id)->update(['name' => 'ppf_leader']);
        }
    }
};
