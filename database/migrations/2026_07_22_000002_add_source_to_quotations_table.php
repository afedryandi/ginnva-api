<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membedakan quotation yang datang dari customer (mobile/web) vs yang
 * diinput manual staff lewat Filament (mis. lead walk-in) — dipakai
 * QuotationObserver supaya notifikasi "Lead Baru" (email + push) cuma
 * terkirim untuk yang benar-benar dari customer, bukan quotation yang
 * staff toko sendiri baru saja buat. Default 'customer' supaya data lama
 * (yang semuanya memang dari customer, fitur create manual staff baru
 * ditambahkan belakangan) tetap benar tanpa perlu backfill terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->enum('source', ['customer', 'staff'])
                  ->default('customer')
                  ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
