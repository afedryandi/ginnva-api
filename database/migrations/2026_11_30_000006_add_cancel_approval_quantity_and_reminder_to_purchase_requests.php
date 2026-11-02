<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permohonan Pembelian (audit 2026-11-02): jumlah yang disetujui (boleh lebih kecil dari yang diminta), status "Dibatalkan" (pemohon
 * membatalkan permohonannya sendiri selagi menunggu), dan penanda pengingat penyetuju. Hanya menambah kolom/nilai enum, aman dijalankan di
 * data yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->decimal('quantity_approved', 12, 2)->nullable()->after('quantity');
            $table->timestamp('last_reminded_at')->nullable()->after('reviewed_at');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE purchase_requests MODIFY status ENUM('pending','approved','rejected','fulfilled','cancelled') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Permohonan yang dibatalkan dipetakan ke "ditolak" supaya nilai enum lama tetap sah.
            DB::table('purchase_requests')->where('status', 'cancelled')->update(['status' => 'rejected']);
            DB::statement("ALTER TABLE purchase_requests MODIFY status ENUM('pending','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending'");
        }

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn(['quantity_approved', 'last_reminded_at']);
        });
    }
};
