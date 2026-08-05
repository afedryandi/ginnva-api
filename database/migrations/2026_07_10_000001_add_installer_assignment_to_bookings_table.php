<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penugasan installer per booking — role baru 'installer' hanya boleh
 * lihat & chat di booking yang di-assign ke dirinya (beda dari
 * store_manager yang scope-nya 1 toko penuh). Ditugaskan oleh Store
 * Manager/Direksi lewat Filament (Select field di BookingResource).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('installer_user_id')->nullable()->after('store_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('installer_user_id');
        });
    }
};
