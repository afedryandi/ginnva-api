<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Akun customer untuk mobile app (tab "我的" / Akun Saya di mini app
     * referensi WeChat Ginnva China). SENGAJA tabel terpisah dari `users`
     * (akun admin Filament) — guard auth juga dibuat terpisah ('customer'
     * vs 'api'), supaya token admin dan token customer tidak bisa
     * saling dipakai untuk akses endpoint yang salah.
     *
     * email & phone_number keduanya nullable dan login bisa pakai
     * salah satu — desain ini sengaja fleksibel karena untuk versi awal
     * hanya email+OTP yang aktif, sementara WhatsApp OTP direncanakan
     * ditambah belakangan setelah provider WA Business API siap, TANPA
     * perlu migrasi skema ulang.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone_number')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
