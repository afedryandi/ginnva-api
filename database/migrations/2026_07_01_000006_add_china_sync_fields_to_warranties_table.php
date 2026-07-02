<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Field tambahan supaya sertifikat garansi kita sejajar dengan
     * dokumen dari Ginnva China (lihat hasil perbandingan OCR sertifikat
     * China): VIN (nomor rangka, permanen — beda dari plat nomor yang
     * bisa berubah), Installation Position (khusus PPF, bagian mobil
     * mana yang dilapisi), dan Roll Number (khusus Window Film, nomor
     * batch produksi untuk traceability recall/klaim cacat produksi).
     *
     * product_category ditambahkan di sini juga karena field
     * installation_position & roll_number visibility-nya kondisional
     * berdasarkan kategori ini (PPF vs Window Film), jadi perlu
     * disimpan per-warranty, bukan cuma diturunkan dari product_series
     * (yang isinya teks bebas seperti "Ziwei 70").
     */
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->enum('product_category', ['window_film', 'ppf', 'color_change'])
                ->nullable()
                ->after('product_series');

            $table->string('vin')->nullable()->after('product_category');

            // Kondisional: hanya relevan untuk PPF
            $table->string('installation_position')->nullable()->after('vin');

            // Kondisional: hanya relevan untuk Window Film
            $table->string('roll_number')->nullable()->after('installation_position');
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->dropColumn(['product_category', 'vin', 'installation_position', 'roll_number']);
        });
    }
};