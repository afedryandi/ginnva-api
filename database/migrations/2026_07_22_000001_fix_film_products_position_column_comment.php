<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KOREKSI dari asumsi awal migration ini (yang salah): sempat dikira
 * 'all' cuma sisa dokumentasi basi karena enum kolom cuma ['front',
 * 'side_rear']. Ternyata SALAH — dicek langsung ke data produksi, ada 4
 * FilmProduct nyata (Black Crystal M8-M, Orange Crystal M10/H10, Green
 * Crystal EV7) yang position-nya memang 'all', diinput sebelum enum-nya
 * "dikencangkan" jadi cuma 2 opsi. Migration versi awal gagal di-run di
 * production persis karena ini (SQLSTATE 1265 "Data truncated").
 *
 * Fix sebenarnya: tambahkan 'all' KEMBALI ke enum (bukan dihapus dari
 * dokumentasi), supaya data yang sudah ada tidak truncated dan produk
 * dengan position='all' tetap valid. Query dropdown ScrollCode di
 * WarrantyResource (roll_number_front / roll_number_side_rear) juga
 * disesuaikan di commit terpisah supaya produk 'all' muncul di KEDUA
 * dropdown, bukan tidak muncul di manapun seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('film_products', function (Blueprint $table) {
            $table->enum('position', ['front', 'side_rear', 'all'])
                  ->default('front')
                  ->comment('Posisi kaca yang sesuai: front=kaca depan, side_rear=samping & belakang, all=bisa dipakai di kedua posisi.')
                  ->change();
        });
    }

    public function down(): void
    {
        Schema::table('film_products', function (Blueprint $table) {
            $table->enum('position', ['front', 'side_rear'])
                  ->default('front')
                  ->comment('Posisi kaca yang sesuai: front=kaca depan, side_rear=samping & belakang, all=semua posisi')
                  ->change();
        });
    }
};
