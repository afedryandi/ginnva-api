<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap DIPERBAIKI 2026-09-26 (audit Voucher Promo) -- SEBELUMNYA voucher
 * murni status tracking administratif, tidak pernah benar-benar memotong
 * transaction_amount booking (bookings.voucher_claim_id sudah ada sejak
 * 2026-07-13 tapi tidak pernah dipakai/dibaca di mana pun). Kolom ini =
 * snapshot potongan voucher (SAMA pola dengan spend_promo_discount) --
 * "kotor" = transaction_amount (net) + voucher_discount + spend_promo_discount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('voucher_discount', 14, 2)->nullable()->after('voucher_claim_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('voucher_discount');
        });
    }
};
