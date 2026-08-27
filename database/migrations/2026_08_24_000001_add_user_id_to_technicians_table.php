<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Technician (roster level/sertifikasi) sebelumnya berdiri sendiri, tidak
 * terhubung ke sistem assignment booking yang sebenarnya (installers —
 * User ber-role 'installer', lihat migration add_installer_assignment_to_
 * bookings_table). Kolom ini menautkan 1 baris Technician ke 1 akun User,
 * supaya level sertifikasi bisa ditampilkan di sisi installer yang
 * sungguh-sungguh ditugaskan ke booking — bukan sekadar roster terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('store_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
