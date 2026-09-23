<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matriks Hak Akses granular per-USER (audit Majoo f64, diminta
 * 2026-09-23) — sebelumnya `menu_access` cuma daftar resource yang
 * BOLEH DILIHAT (all-or-nothing per menu), tidak ada cara membedakan
 * "boleh Lihat tapi tidak boleh Ubah/Hapus" dalam 1 resource yang sama.
 * Keputusan user 2026-09-23: TETAP per-user (bukan per-role, `roles`
 * di Ginnva sengaja cuma label/pengelompokan, lihat RoleResource.php),
 * jadi field baru ini bukan pengganti `menu_access` — ADA DI ATASNYA:
 * `menu_access` = "resource ini boleh dilihat sama sekali atau tidak",
 * `menu_permissions` = kalau boleh dilihat, aksi mana saja yang boleh
 * (view/create/update/delete/void) di resource itu.
 *
 * Format: {"BookingResource": ["view", "create", "update"], ...} — key
 * pakai class_basename Resource, value daftar aksi yang DIIZINKAN.
 * NULL (belum pernah diatur admin) = default sesuai per-aksi (lihat
 * User::hasModuleAction()) supaya akun yang sudah ada TIDAK diam-diam
 * kehilangan kemampuan yang sudah biasa mereka pakai (view/create/
 * update tetap jalan seperti biasa), tapi aksi BARU yang sebelumnya
 * memang belum ada (delete granular, void) SENGAJA default TIDAK
 * boleh sampai admin secara eksplisit mencentangnya — supaya migrasi
 * fitur ini tidak diam-diam menaikkan hak akses siapa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('menu_permissions')->nullable()->after('menu_access');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('menu_permissions');
        });
    }
};
