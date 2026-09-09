<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diminta 2026-09-09 (analog "Laporan Refund" Majoo). Aturan bisnis
 * DIKONFIRMASI user:
 * - Refund BISA PARSIAL (bukan selalu penuh).
 * - Setiap refund WAJIB bikin jurnal balik OTOMATIS (kontra) di Jurnal
 *   Umum -- lihat RefundService.
 *
 * TIDAK mengubah/menghapus journal_entries lama sama sekali (praktik
 * akuntansi standar: entry historis tidak pernah diedit) -- refund
 * bikin JournalEntry BARU yang membalik sebagian pendapatan, refunds
 * ini cuma jadi jembatan pencatatan + link ke jurnal kontra-nya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_number')->unique();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->text('reason')->nullable();
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
