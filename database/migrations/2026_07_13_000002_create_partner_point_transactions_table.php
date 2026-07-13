<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mirror dari point_transactions (customer) tapi untuk partner —
        // dipisah tabel (bukan dibuat polymorphic) supaya tidak perlu
        // migrasi ulang tabel point_transactions yang sudah dipakai
        // production untuk customer.
        Schema::create('partner_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->enum('type', ['earn', 'spend']);
            $table->unsignedInteger('points');
            $table->string('description', 255);
            $table->string('reference_type', 50)->nullable(); // 'booking', 'reward_redemption'
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_point_transactions');
    }
};
