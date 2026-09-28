<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Kategori Keuangan 2026-09-28 (gap enterprise):
 * - finance_categories: kode (opsional, unik), deskripsi, dan hierarki 1
 *   tingkat: kategori "grup" (is_group, tidak menerima transaksi, tidak
 *   punya akun) membungkus kategori anak (parent_id). Kategori yang sudah
 *   ada tetap datar (is_group=false, parent null) -- tidak berubah.
 * - finance_transactions.chart_of_account_id: SNAPSHOT akun kategori saat
 *   transaksi dibuat/kategorinya diganti, supaya laporan berbasis akun tidak
 *   bergantung pada akun kategori "saat ini". Di-backfill dari kategori
 *   transaksi yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('id');
            $table->text('description')->nullable()->after('name');
            $table->boolean('is_group')->default(false)->after('type');
            $table->foreignId('parent_id')->nullable()->after('is_group')
                ->constrained('finance_categories')->nullOnDelete();
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreignId('chart_of_account_id')->nullable()->after('finance_category_id')
                ->constrained('chart_of_accounts')->nullOnDelete();
        });

        // Backfill snapshot dari akun kategori saat ini (satu UPDATE join).
        DB::statement('
            UPDATE finance_transactions
            INNER JOIN finance_categories ON finance_categories.id = finance_transactions.finance_category_id
            SET finance_transactions.chart_of_account_id = finance_categories.chart_of_account_id
            WHERE finance_transactions.chart_of_account_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chart_of_account_id');
        });

        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['is_group', 'description', 'code']);
        });
    }
};
