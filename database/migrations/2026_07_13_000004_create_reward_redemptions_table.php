<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            // redeemer_type: 'partner' atau 'customer' — dua tabel akun
            // berbeda (partners / customers), bukan morphTo Eloquent biasa
            // supaya tidak perlu base class bersama.
            $table->enum('redeemer_type', ['partner', 'customer']);
            $table->unsignedBigInteger('redeemer_id');
            $table->foreignId('reward_id')->constrained('rewards')->cascadeOnDelete();
            $table->unsignedInteger('points_spent');
            $table->enum('status', ['pending', 'fulfilled', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['redeemer_type', 'redeemer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_redemptions');
    }
};
