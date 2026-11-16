<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rincian pemasangan PPF: ppf_area 'exterior'/'interior'; ppf_scope 'full_body' (sudah termasuk Detailing + Premium Wash, tanpa
 * tagihan terpisah) atau 'partial' (Part, daftar panelnya di ppf_parts, hanya Eksterior). Null = booking lama / bukan PPF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('ppf_area', 20)->nullable()->after('product_ppf');
            $table->string('ppf_scope', 20)->nullable()->after('ppf_area');
            $table->json('ppf_parts')->nullable()->after('ppf_scope');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['ppf_area', 'ppf_scope', 'ppf_parts']);
        });
    }
};
