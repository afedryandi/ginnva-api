<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Surat Peringatan (SP1/SP2/SP3) — dikeluarkan LANGSUNG oleh admin/store
 * manager (bukan alur permintaan+approval seperti PurchaseRequest/
 * LeaveRequest, karena SP itu sendiri SUDAH keputusan final, bukan
 * pengajuan yang menunggu disetujui pihak lain).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warning_letters', function (Blueprint $table) {
            $table->id();
            $table->string('warning_number')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->enum('level', ['sp1', 'sp2', 'sp3']);
            $table->text('reason');
            $table->date('issued_date');
            $table->date('valid_until')->nullable();
            $table->string('document')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warning_letters');
    }
};
