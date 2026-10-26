<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pelepasan aset (audit ACCURATE 5, "Dispos"): aset yang dijual atau hilang/dibuang dikeluarkan dari pembukuan dengan satu jurnal
     * (harga perolehan, akumulasi penyusutan, hasil penjualan, laba atau rugi pelepasan). Semua kolom boleh kosong: aset lama tidak berubah.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->date('disposed_at')->nullable()->after('status');
            $table->string('disposal_type', 10)->nullable()->after('disposed_at');
            $table->decimal('disposal_proceeds', 15, 2)->nullable()->after('disposal_type');
            $table->foreignId('disposal_journal_entry_id')->nullable()->after('disposal_proceeds')->constrained('journal_entries')->nullOnDelete();
            $table->text('disposal_notes')->nullable()->after('disposal_journal_entry_id');

            $table->index('disposed_at');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['disposed_at']);
            $table->dropConstrainedForeignId('disposal_journal_entry_id');
            $table->dropColumn(['disposed_at', 'disposal_type', 'disposal_proceeds', 'disposal_notes']);
        });
    }
};
