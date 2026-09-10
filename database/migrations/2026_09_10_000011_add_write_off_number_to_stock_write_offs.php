<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor transaksi untuk Stok Terbuang (WO-YYYYMMDD-XXXX) — samakan dgn
 * kolom "Nomor" di Majoo. Nullable dulu supaya baris lama (kalau ada)
 * tidak menolak, lalu di-backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_write_offs', function (Blueprint $table) {
            $table->string('write_off_number')->nullable()->unique()->after('id');
        });

        // Backfill baris yg sudah ada (jarang — fitur baru 2026-09-10).
        $rows = DB::table('stock_write_offs')->whereNull('write_off_number')->orderBy('id')->get(['id', 'created_at']);
        $perDay = [];
        foreach ($rows as $row) {
            $date = \Illuminate\Support\Carbon::parse($row->created_at)->format('Ymd');
            $perDay[$date] = ($perDay[$date] ?? 0) + 1;
            DB::table('stock_write_offs')->where('id', $row->id)->update([
                'write_off_number' => sprintf('WO-%s-%04d', $date, $perDay[$date]),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('stock_write_offs', function (Blueprint $table) {
            $table->dropUnique(['write_off_number']);
            $table->dropColumn('write_off_number');
        });
    }
};
