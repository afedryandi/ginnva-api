<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Bagan Akun 2026-09-28: bank_statement_lines dan
 * finance_dashboard_widgets memakai cascadeOnDelete pada
 * chart_of_account_id, jadi menghapus akun (lewat jalur yang tidak
 * melewati guard aplikasi: tinker, script, job) menghapus SELURUH riwayat
 * mutasi bank / widget dashboard diam-diam. Diganti restrictOnDelete --
 * sama dengan journal_entry_lines & recurring_bill_templates: database
 * MENOLAK hapus akun yang masih dipakai.
 *
 * Tidak mengubah/menghapus data apa pun, hanya perilaku hapus ke depan.
 * Reversible: down() mengembalikan cascadeOnDelete. Sesuai RUNBOOK §3,
 * tetap disarankan backup manual sebelum dijalankan di production.
 */
return new class extends Migration
{
    private const TABLES = ['bank_statement_lines', 'finance_dashboard_widgets'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['chart_of_account_id']);
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('chart_of_account_id')
                    ->references('id')->on('chart_of_accounts')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['chart_of_account_id']);
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('chart_of_account_id')
                    ->references('id')->on('chart_of_accounts')
                    ->cascadeOnDelete();
            });
        }
    }
};
