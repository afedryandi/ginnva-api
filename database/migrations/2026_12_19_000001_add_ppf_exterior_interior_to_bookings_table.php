<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PPF Eksterior dan Interior boleh dipilih bersamaan (permintaan pemilik 2026-12-19): dua penanda terpisah menggantikan pilihan tunggal ppf_area.
 * Kolom ppf_area tetap ada dan kini diturunkan otomatis dari dua penanda ini ('exterior', 'interior', 'exterior_interior'), supaya pembaca lama
 * tidak rusak. Booking PPF yang sudah ada diisi sesuai ppf_area lamanya; yang belum menentukan dianggap Eksterior (sama dengan perilaku sebelumnya,
 * tempat bonus Detailing dan Premium Wash Full Body berlaku kecuali Interior).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('ppf_exterior')->default(false)->after('ppf_area');
            $table->boolean('ppf_interior')->default(false)->after('ppf_exterior');
        });

        DB::table('bookings')->where('product_ppf', true)->where(fn ($q) => $q->whereNull('ppf_area')->orWhere('ppf_area', '!=', 'interior'))->update(['ppf_exterior' => true]);
        DB::table('bookings')->where('product_ppf', true)->where('ppf_area', 'interior')->update(['ppf_interior' => true]);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['ppf_exterior', 'ppf_interior']);
        });
    }
};
