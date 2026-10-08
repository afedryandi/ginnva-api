<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Akun bertipe 'pajak' (Beban Pajak, mis. 8100) tersimpan dengan saldo normal KREDIT karena tipe 'pajak'
 * terlewat di ChartOfAccount::DEBIT_NORMAL_TYPES. Akibatnya Laporan Laba Rugi MENAMBAH beban pajak ke laba
 * bersih (dan Neraca Saldo menampilkan saldonya terbalik). Beban pajak adalah debit-normal.
 *
 * Hanya mengubah kolom normal_balance (tidak menyentuh jurnal); langsung lewat query builder supaya guard
 * model tidak ikut berjalan. Aman diulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('chart_of_accounts')->where('type', 'pajak')->update(['normal_balance' => 'debit']);
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')->where('type', 'pajak')->update(['normal_balance' => 'kredit']);
    }
};
