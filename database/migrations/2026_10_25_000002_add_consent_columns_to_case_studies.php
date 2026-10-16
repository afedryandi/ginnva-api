<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Galeri Pemasangan (Marketing/Konten Fase 3): materi galeri yang bersumber dari booking membawa izin pelanggan, jenis layanan, foto
 * sebelum, teks alternatif, dan penanda plat nomor sudah diburamkan. vehicle_id dan film_product_id menjadi opsional supaya layanan
 * tanpa film (Detailing, Premium Wash) dan kendaraan yang belum ada di master Kendaraan tetap bisa dipamerkan. Baris lama tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_studies', function (Blueprint $table) {
            if (! Schema::hasColumn('case_studies', 'booking_id')) {
                $table->foreignId('booking_id')->nullable()->after('id')->constrained('bookings')->nullOnDelete();
            }
            if (! Schema::hasColumn('case_studies', 'marketing_consent_id')) {
                $table->foreignId('marketing_consent_id')->nullable()->after('booking_id')->constrained('marketing_consents')->nullOnDelete();
            }
            if (! Schema::hasColumn('case_studies', 'service_type')) {
                $table->string('service_type', 20)->nullable();
            }
            if (! Schema::hasColumn('case_studies', 'before_image')) {
                $table->string('before_image')->nullable();
            }
            if (! Schema::hasColumn('case_studies', 'alt_text')) {
                $table->string('alt_text', 255)->nullable();
            }
            if (! Schema::hasColumn('case_studies', 'plate_blurred')) {
                $table->boolean('plate_blurred')->default(false);
            }
        });

        Schema::table('case_studies', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicle_id')->nullable()->change();
            $table->unsignedBigInteger('film_product_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('case_studies', function (Blueprint $table) {
            foreach (['booking_id', 'marketing_consent_id'] as $fk) {
                if (Schema::hasColumn('case_studies', $fk)) {
                    $table->dropConstrainedForeignId($fk);
                }
            }
            foreach (['service_type', 'before_image', 'alt_text', 'plate_blurred'] as $column) {
                if (Schema::hasColumn('case_studies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // vehicle_id/film_product_id sengaja TIDAK dikembalikan ke NOT NULL: baris bertanda kosong akan membuat pengembalian gagal.
    }
};
