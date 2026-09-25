<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap DIPERBAIKI 2026-09-25 (audit Garansi, "transfer garansi ke
 * pemilik baru tidak dipertimbangkan sama sekali") -- wajar terjadi di
 * industri PPF/WF (mobil dijual, pemilik baru ingin klaim garansi sisa
 * masa berlaku). Keputusan user: staff-only (Filament), penerima BOLEH
 * tanpa akun customer (data teks bebas, sama seperti garansi baru bisa
 * tanpa customer_id), riwayat pemilik lama tampil eksplisit (bukan cuma
 * activity_log mentah) -- makanya perlu tabel sendiri, bukan cukup
 * andalkan LogsActivity Warranty (yang cuma catat kolom yang di-log,
 * tidak didesain untuk ditampilkan sebagai timeline kepemilikan).
 *
 * Snapshot NAMA (bukan cuma FK) sengaja disimpan di kedua sisi (lama &
 * baru) -- supaya riwayat tetap terbaca walau customer_id-nya null
 * (transfer ke non-akun) atau akun itu nanti berganti nama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_ownership_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warranty_id')->constrained()->cascadeOnDelete();

            $table->foreignId('previous_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('previous_customer_name');
            $table->string('previous_phone_number')->nullable();

            $table->foreignId('new_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('new_customer_name');
            $table->string('new_phone_number')->nullable();

            $table->text('note')->nullable();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transferred_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_ownership_transfers');
    }
};
