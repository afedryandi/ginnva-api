<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Potongan Sisa (wasted) PPF: potongan besar yang tidak terpakai saat roll dikerjakan, disimpan gudang dan kadang dipakai lagi untuk booking
 * kecil (mis. interior). Satu baris = satu potongan, tanpa ukuran (praktik gudang: asal simpan, ambil saat perlu). Tiap potongan terikat ke
 * SATU kode gulungan asalnya supaya garansi dan traceability tidak putus; potongan bukan stok inventori dan tidak bernilai uang.
 * Dipakai lewat baris memo bertipe 'offcut' beserta bagian mobil yang dikerjakan (kolom car_part).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scroll_code_offcuts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scroll_code_id')->constrained('scroll_codes')->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('origin_memo_id')->nullable()->constrained('material_memos')->nullOnDelete();
            $table->string('location', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('status', 20)->default('stored');
            $table->foreignId('used_in_memo_item_id')->nullable()->constrained('material_memo_items')->nullOnDelete();
            $table->string('car_part', 100)->nullable();
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discarded_at')->nullable();
            $table->foreignId('discarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('discard_reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['scroll_code_id', 'status']);
            $table->index(['store_id', 'status']);
        });

        Schema::table('material_memo_items', function (Blueprint $table) {
            $table->string('car_part', 100)->nullable()->after('condition_notes');
        });
    }

    public function down(): void
    {
        Schema::table('material_memo_items', function (Blueprint $table) {
            $table->dropColumn('car_part');
        });

        Schema::dropIfExists('scroll_code_offcuts');
    }
};
