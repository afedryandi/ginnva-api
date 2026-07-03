<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            // customer_id nullable — anonymous (guest) token tidak punya akun
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token')->unique();
            // android | ios
            $table->string('platform')->default('android');
            $table->timestamps();

            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
