<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link ke Jurnal Umum Pendapatan yang otomatis dibuat saat kasir
 * input/ubah "Nominal Transaksi" lewat aksi "Proses Referral" (lihat
 * BookingPostingService & BookingResource action 'process_referral').
 * Nullable — booking yang belum completed/belum diisi nominal
 * transaksi tidak akan pernah punya jurnal sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });
    }
};
