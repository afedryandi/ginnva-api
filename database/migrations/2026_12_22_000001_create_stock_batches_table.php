<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch Penerimaan (2026-11-23): mengelompokkan barang yang masuk bersamaan, baik PPF/WF (gulungan dan kardus) maupun bahan baku.
     * Nomor dibuat otomatis (BTH-yymmdd-NNN). Pemasok dan nomor faktur opsional. Data lama dibiarkan tanpa batch (kolom null).
     * Murni tambahan: tidak mengubah kolom lama, aman dibalik (down menghapus kolom dan tabel baru saja).
     */
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 32)->unique();
            $table->string('type', 20); // ppf_wf | raw_material
            $table->date('received_date');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('invoice_number', 80)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'received_date']);
        });

        foreach (['scroll_codes', 'inventory_items', 'raw_material_batches'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['scroll_codes', 'inventory_items', 'raw_material_batches'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropConstrainedForeignId('stock_batch_id');
            });
        }

        Schema::dropIfExists('stock_batches');
    }
};
