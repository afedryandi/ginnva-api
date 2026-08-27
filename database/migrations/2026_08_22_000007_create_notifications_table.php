<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel notifikasi standar Laravel — dipakai Filament database
 * notifications (bell icon di panel admin) untuk alert kedaluwarsa
 * bahan baku yang sekarang aktif (dikirim oleh
 * App\Console\Commands\NotifyExpiringMaterials), BUKAN cuma badge pasif
 * yang baru kelihatan kalau admin sendiri buka halaman Bahan Baku.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
