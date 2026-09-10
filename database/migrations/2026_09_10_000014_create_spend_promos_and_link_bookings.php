<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Promo Per Total Pembelian" (spend-threshold) — diminta 2026-09-10,
 * analog menu Majoo "Promo > Per Total Pembelian". Aturan dikonfirmasi
 * user: bonus = POTONGAN HARGA LANGSUNG (flat Rp), diterapkan MANUAL
 * oleh staf ke booking yang memenuhi syarat minimal pembelian.
 *
 * MODEL: bookings.transaction_amount disimpan NET (sudah dipotong) —
 * konsisten dengan cara staf mengisi nominal nego manual. Kolom
 * spend_promo_discount = snapshot potongan yang diberikan (untuk
 * laporan & cek ambang: nominal kotor = transaction_amount +
 * spend_promo_discount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spend_promos', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('min_purchase_amount', 14, 2);
            $table->decimal('discount_amount', 14, 2);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('spend_promo_id')->nullable()->after('voucher_claim_id')
                ->constrained('spend_promos')->nullOnDelete();
            $table->decimal('spend_promo_discount', 14, 2)->nullable()->after('spend_promo_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('spend_promo_id');
            $table->dropColumn('spend_promo_discount');
        });

        Schema::dropIfExists('spend_promos');
    }
};
