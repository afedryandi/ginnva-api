<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking menyimpan SALINAN data kendaraan saat dibuat (Fase 1 pengerjaan ulang booking, 2026-10-15), supaya riwayat
 * booking tidak berubah kalau pelanggan kemudian mengubah atau menghapus kendaraannya. Semua kolom nullable: booking lama
 * dan booking dari aplikasi versi lama (tanpa kendaraan) tetap valid. `vehicle_size` sudah ada sejak 2026-09-22.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'customer_vehicle_id')) {
                $table->foreignId('customer_vehicle_id')->nullable()->after('customer_id')->constrained('customer_vehicles')->nullOnDelete();
            }
            if (! Schema::hasColumn('bookings', 'vehicle_plate')) {
                $table->string('vehicle_plate', 20)->nullable()->after('vehicle_size');
                $table->string('vehicle_brand', 80)->nullable()->after('vehicle_plate');
                $table->string('vehicle_model', 120)->nullable()->after('vehicle_brand');
                $table->string('vehicle_variant', 120)->nullable()->after('vehicle_model');
                $table->string('vehicle_color', 40)->nullable()->after('vehicle_variant');
                $table->unsignedSmallInteger('vehicle_year')->nullable()->after('vehicle_color');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'customer_vehicle_id')) {
                $table->dropConstrainedForeignId('customer_vehicle_id');
            }
            foreach (['vehicle_plate', 'vehicle_brand', 'vehicle_model', 'vehicle_variant', 'vehicle_color', 'vehicle_year'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
