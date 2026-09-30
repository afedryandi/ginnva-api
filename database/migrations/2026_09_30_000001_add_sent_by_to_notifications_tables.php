<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Kirim Notifikasi 2026-09-30 -- sebelumnya tidak ada jejak siapa
 * staff yang memicu broadcast/notifikasi tertentu, beda dari hampir semua
 * aksi sensitif lain yang sudah tercatat causedBy() di audit-audit
 * sebelumnya. Nullable + nullOnDelete supaya baris riwayat lama & staff
 * yang akunnya sudah dihapus tetap tidak bikin baris riwayat error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->foreignId('sent_by')->nullable()->after('data')->constrained('users')->nullOnDelete();
        });

        Schema::table('partner_notifications', function (Blueprint $table) {
            $table->foreignId('sent_by')->nullable()->after('data')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by');
        });

        Schema::table('partner_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by');
        });
    }
};
