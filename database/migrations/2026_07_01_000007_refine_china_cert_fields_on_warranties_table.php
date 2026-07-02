<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Revisi berdasarkan transkripsi lengkap sertifikat China (field
     * asli 车架号/卷芯号/装贴部位 dll). Ternyata field-nya lebih rinci
     * dari perkiraan awal:
     *
     * - PPF: installation_position bukan teks bebas, tapi pilihan tetap
     *   "Seluruh Bodi" / "Parsial" (装贴部位: [ ] 整车 [ ] 局部). Tambah
     *   installation_position_detail untuk keterangan area spesifik
     *   kalau dipilih Parsial.
     * - Window Film: roll_number & product_series ternyata dipisah 2
     *   (Kaca Depan vs Kaca Samping & Belakang), karena keduanya sering
     *   pakai roll film berbeda. Kolom roll_number lama (generik)
     *   di-drop, diganti roll_number_front & roll_number_side_rear.
     *   Kolom film_model_front & film_model_side_rear ditambahkan
     *   sebagai pengganti product_series khusus untuk kategori Window
     *   Film (product_series tetap dipakai apa adanya untuk PPF/Color
     *   Change, sesuai field "PPF Model" di sertifikat).
     */
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            // Ganti enum installation_position (string) jadi pilihan tetap
            $table->dropColumn('installation_position');
        });

        Schema::table('warranties', function (Blueprint $table) {
            $table->enum('installation_position', ['full_body', 'partial'])
                ->nullable()
                ->after('vin');

            $table->string('installation_position_detail')
                ->nullable()
                ->after('installation_position');

            // roll_number lama digantikan roll_number_front/side_rear
            // untuk Window Film. Untuk PPF, roll_number lama (satu-satunya,
            // "Roll/ID Material") tetap dipertahankan apa adanya.
            $table->string('roll_number_front')
                ->nullable()
                ->after('roll_number');

            $table->string('roll_number_side_rear')
                ->nullable()
                ->after('roll_number_front');

            $table->string('film_model_front')
                ->nullable()
                ->after('roll_number_side_rear');

            $table->string('film_model_side_rear')
                ->nullable()
                ->after('film_model_front');
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->dropColumn([
                'installation_position',
                'installation_position_detail',
                'roll_number_front',
                'roll_number_side_rear',
                'film_model_front',
                'film_model_side_rear',
            ]);
        });

        Schema::table('warranties', function (Blueprint $table) {
            $table->string('installation_position')->nullable()->after('vin');
        });
    }
};
