<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * device_tokens sebelumnya hanya untuk customer (mobile app end-user).
 * Sekarang staff (admin toko/super_admin) juga login di mobile app yang
 * sama untuk fitur chat & progress instalasi — mereka butuh push
 * notification juga saat ada booking/chat baru, jadi token mereka perlu
 * disimpan di tabel yang sama, dibedakan lewat user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('customer_id')
                ->constrained('users')->nullOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
