<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Diisi staff toko saat booking selesai & customer bayar —
            // memicu pemberian poin ke partner (via kode) & customer.
            $table->string('referral_code', 20)->nullable()->after('current_stage');
            $table->decimal('transaction_amount', 12, 2)->nullable()->after('referral_code');
            $table->foreignId('partner_id')->nullable()->after('transaction_amount')
                ->constrained('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
            $table->dropColumn(['referral_code', 'transaction_amount']);
        });
    }
};
