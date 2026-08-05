<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror dari customer_notifications — Partner (mitra referral) sebelumnya
 * tidak punya riwayat notifikasi sama sekali di dalam app, cuma push
 * sekali lewat (kalau app tertutup, notifnya hilang). Struktur & alasan
 * desainnya sama persis dengan customer_notifications: partner_id null =
 * broadcast ke semua partner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_notifications');
    }
};
