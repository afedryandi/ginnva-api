<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda "booking ini termasuk jasa Premium Wash" — sejajar persis
 * dengan product_detailing (migrasi 2026_09_10_000003). Premium Wash
 * dijual dua-duanya (sendiri atau tambahan pada booking film), sama
 * seperti Detailing, dikonfirmasi user 2026-09-22.
 *
 * SENGAJA tidak diikutkan ke mesin tahap/progress booking maupun ke
 * opsi service_type / mobile app — sama alasan dengan product_detailing,
 * ini bukan alur instalasi bertahap seperti film.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('product_premium_wash')->default(false)->after('product_detailing');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('product_premium_wash');
        });
    }
};
