<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifikasi Fase 3 (2026-10-21): satu baris per token yang dikirimi push, supaya "terkirim" berarti terkirim.
 * status: accepted (tiket diterima Expo, kuitansi belum diperiksa), delivered (kuitansi ok), failed, dead_token
 * (DeviceNotRegistered, token dihapus), unknown (kuitansi tidak pernah muncul dalam 23 jam).
 * Tanpa foreign key: log harus bertahan walau akun atau token dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('token');
            $table->string('title');
            $table->string('ticket_id', 64)->nullable()->index();
            $table->string('status', 20);
            $table->string('error')->nullable();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('checked_at')->nullable();

            $table->index(['status', 'sent_at']);
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
