<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Audit Hutang Usaha 2026-09-29 (gap enterprise):
 * - Master Supplier (nama, NPWP, rekening, kontak) + payables.supplier_id.
 *   Data lama di-backfill: tiap supplier_name unik jadi 1 supplier.
 *   supplier_name di payables TETAP disimpan sebagai snapshot nama saat tagihan dibuat.
 * - payables.invoice_number & attachment (scan/foto invoice supplier).
 * - payables.source_key UNIK -- kunci idempotensi per sumber (1 Permohonan Pembelian =
 *   1 tagihan; 1 template rutin = 1 tagihan per tanggal). Backfill: kalau data lama sudah
 *   punya duplikat, HANYA baris pertama yang diberi key (sisanya dibiarkan tanpa key +
 *   dicatat di log untuk ditinjau manual) -- tidak ada data yang dihapus/diubah nominalnya.
 * - CHECK constraint (amount > 0, 0 <= amount_paid <= amount) sebagai lapisan kedua di
 *   bawah validasi service; dilewati (dengan log) bila ada data lama yang melanggar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('npwp', 30)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number', 50)->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
        });

        Schema::table('payables', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('supplier_name')->constrained('suppliers')->nullOnDelete();
            $table->string('invoice_number', 100)->nullable()->after('payable_number');
            $table->string('attachment')->nullable()->after('notes');
            $table->string('source_key')->nullable()->after('source_id');
            $table->unique('source_key');
            $table->index('due_date');
        });

        // Backfill supplier master dari nama yang sudah ada.
        $names = DB::table('payables')->select('supplier_name')->distinct()->pluck('supplier_name');
        foreach ($names as $name) {
            $id = DB::table('suppliers')->insertGetId([
                'name' => $name,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('payables')->where('supplier_name', $name)->update(['supplier_id' => $id]);
        }

        // Backfill source_key (baris pertama per kunci saja).
        $seen = [];
        foreach (DB::table('payables')->whereNotNull('source_type')->whereNotNull('source_id')->orderBy('id')->get(['id', 'source_type', 'source_id', 'due_date']) as $row) {
            $key = match ($row->source_type) {
                'purchase_request' => "purchase_request:{$row->source_id}",
                'recurring_bill_template' => $row->due_date ? "recurring_bill_template:{$row->source_id}:{$row->due_date}" : null,
                default => null,
            };

            if ($key === null) {
                continue;
            }

            if (isset($seen[$key])) {
                Log::warning("Payable #{$row->id} duplikat sumber '{$key}' (asli: #{$seen[$key]}) -- tidak diberi source_key, mohon ditinjau manual.");

                continue;
            }

            $seen[$key] = $row->id;
            DB::table('payables')->where('id', $row->id)->update(['source_key' => $key]);
        }

        $violations = DB::table('payables')
            ->whereRaw('amount <= 0 OR amount_paid < 0 OR amount_paid > amount')
            ->count();

        if ($violations > 0) {
            Log::warning("CHECK constraint payables dilewati: {$violations} baris lama melanggar aturan nominal.");

            return;
        }

        try {
            DB::statement('ALTER TABLE payables ADD CONSTRAINT chk_payables_amounts CHECK (amount > 0 AND amount_paid >= 0 AND amount_paid <= amount)');
        } catch (\Throwable $e) {
            Log::warning('CHECK constraint payables dilewati: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        try {
            DB::statement('ALTER TABLE payables DROP CHECK chk_payables_amounts');
        } catch (\Throwable $e) {
            // constraint tidak ada / driver tidak mendukung -- abaikan
        }

        Schema::table('payables', function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropIndex(['due_date']);
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['invoice_number', 'attachment', 'source_key']);
        });

        Schema::dropIfExists('suppliers');
    }
};
