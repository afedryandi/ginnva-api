<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kunci idempotensi (Fase 0 pengerjaan ulang booking, 2026-10-14): satu baris per tindakan yang dikirim klien dengan
 * header Idempotency-Key, supaya kiriman ulang (jaringan putus, ketukan ganda) mengembalikan hasil asli, bukan membuat
 * booking kedua. Baris dihapus otomatis setelah kedaluwarsa (idempotency:prune).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('idempotency_keys')) {
            return;
        }

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 60); // pemilik kunci, mis. "customer:12" / "user:3" / "ip:1.2.3.4"
            $table->string('key', 100);
            $table->string('method', 10);
            $table->string('path', 191);
            $table->string('request_hash', 64);
            $table->string('status', 12)->default('processing'); // processing | completed
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at')->index();

            $table->unique(['scope', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
