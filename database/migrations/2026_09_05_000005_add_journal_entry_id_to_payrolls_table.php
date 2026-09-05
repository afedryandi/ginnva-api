<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link ke Jurnal Umum yang otomatis dibuat saat payroll ini ditandai
 * "Dibayar" — lihat PayrollPostingService. Nullable — baris 'draft'
 * belum punya jurnal sama sekali (baru dibuat saat status berubah jadi
 * 'paid', bukan saat digenerate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });
    }
};
