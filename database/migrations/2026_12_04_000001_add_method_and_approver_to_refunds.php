<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refund mencatat METODE pengembalian (tunai, transfer, QRIS, atau EDC; kunci yang sama dengan Booking::PAYMENT_METHOD_LABELS) dan
 * PENYETUJU (akses penuh yang menyetujui pengajuan staf; kosong bila akses penuh memproses langsung). Keduanya kosong untuk refund lama
 * dan tampil sebagai "—".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('method', 20)->nullable()->after('amount');
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('method');
        });
    }
};
