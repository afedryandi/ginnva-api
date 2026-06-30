<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menghubungkan warranty ke akun customer, supaya bisa muncul di
     * "Garansi Saya" (我的质保) di mobile app. NULLABLE — warranty yang
     * disubmit sebagai guest (tanpa login) tetap valid seperti biasa,
     * cuma tidak otomatis muncul di akun siapapun.
     */
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->foreignId('customer_id')
                ->nullable()
                ->after('store_id')
                ->constrained('customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
