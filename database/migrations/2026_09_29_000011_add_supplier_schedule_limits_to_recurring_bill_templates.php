<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Template Tagihan Rutin 2026-09-29 (gap enterprise):
 * - supplier_id (master Supplier) menggantikan teks bebas sebagai sumber kebenaran;
 *   supplier_name tetap disimpan sebagai snapshot. Backfill: cocokkan nama (tanpa peka
 *   kapitalisasi/spasi) ke master, buat master baru kalau belum ada.
 * - end_date & max_occurrences: batas masa berlaku (sewa kontrak 12 bulan, cicilan).
 * - paused_until: jeda terjadwal -- bulan yang jatuh di dalam masa jeda DILEWATI (jadwal
 *   tetap maju, tanpa membuat tagihan), jadi tidak ada catch-up mendadak setelah jeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_bill_templates', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('supplier_name')->constrained('suppliers')->nullOnDelete();
            $table->date('end_date')->nullable()->after('next_run_date');
            $table->unsignedSmallInteger('max_occurrences')->nullable()->after('end_date');
            $table->date('paused_until')->nullable()->after('max_occurrences');
        });

        DB::table('recurring_bill_templates')->whereNull('supplier_id')->orderBy('id')->get(['id', 'supplier_name'])->each(function ($row) {
            $clean = trim(preg_replace('/\s+/', ' ', (string) $row->supplier_name));

            if ($clean === '') {
                return;
            }

            $supplierId = DB::table('suppliers')->whereRaw('LOWER(name) = ?', [mb_strtolower($clean)])->value('id')
                ?? DB::table('suppliers')->insertGetId([
                    'name' => $clean,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('recurring_bill_templates')->where('id', $row->id)->update(['supplier_id' => $supplierId]);
        });
    }

    public function down(): void
    {
        Schema::table('recurring_bill_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['end_date', 'max_occurrences', 'paused_until']);
        });
    }
};
