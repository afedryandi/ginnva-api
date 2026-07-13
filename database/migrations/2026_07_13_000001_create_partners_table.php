<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            // Akun login partner — user dengan role 'partner', sama pola
            // dengan staff (login pakai email+password, guard 'api').
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('business_name');
            $table->string('phone')->nullable();
            // Kode unik yang dibagikan partner ke kenalan/customernya —
            // di-generate otomatis saat partner dibuat di Filament.
            $table->string('referral_code', 20)->unique();
            $table->enum('status', ['active', 'inactive'])->default('active');
            // Saldo poin didenormalisasi di sini (seperti customers.loyalty_points)
            // supaya baca saldo tidak perlu SUM() dari seluruh histori tiap request.
            $table->unsignedInteger('points_balance')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
