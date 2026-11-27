<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penyeragaman data kendaraan di Booking, SPK, dan Memo Barang (2026-11-27, keputusan pemilik).
 *
 *  - SPK: kolom Model dan Varian terpisah. Sebelumnya kolom "Merek" berisi merek + model + varian sekaligus; data SPK lama TIDAK dipisah
 *    (tidak bisa dipastikan, misalnya "Land Rover Defender"), tetap utuh di kolom Merek.
 *  - Memo: kolom Varian dan Tahun baru; Merek dan Model dilebarkan (aman, hanya memperlebar) supaya isian dari booking tidak terpotong.
 *  - Plat di tiga tabel dirapikan sekali (huruf besar, spasi tunggal). Seterusnya dirapikan otomatis saat menyimpan.
 *
 * Tidak ada data yang dihapus. down() hanya menghapus kolom yang ditambahkan di sini; plat yang sudah dirapikan tidak dikembalikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spks', function (Blueprint $table) {
            $table->string('vehicle_model', 120)->nullable()->after('vehicle_brand');
            $table->string('vehicle_variant', 120)->nullable()->after('vehicle_model');
        });

        Schema::table('material_memos', function (Blueprint $table) {
            $table->string('vehicle_brand', 80)->nullable()->change();
            $table->string('vehicle_model', 120)->nullable()->change();
            $table->string('vehicle_variant', 120)->nullable()->after('vehicle_model');
            $table->unsignedSmallInteger('vehicle_year')->nullable()->after('vehicle_variant');
        });

        foreach (['bookings', 'spks', 'material_memos'] as $table) {
            DB::table($table)->whereNotNull('vehicle_plate')->orderBy('id')->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $clean = trim((string) preg_replace('/\s+/', ' ', (string) $row->vehicle_plate));
                    $clean = $clean === '' ? null : mb_strtoupper($clean);

                    if ($clean !== $row->vehicle_plate) {
                        DB::table($table)->where('id', $row->id)->update(['vehicle_plate' => $clean]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('material_memos', function (Blueprint $table) {
            $table->dropColumn(['vehicle_variant', 'vehicle_year']);
        });

        Schema::table('spks', function (Blueprint $table) {
            $table->dropColumn(['vehicle_model', 'vehicle_variant']);
        });
    }
};
