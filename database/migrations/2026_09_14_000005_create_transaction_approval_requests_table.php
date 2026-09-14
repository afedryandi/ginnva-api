<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit framework 2026-09-14, "Segregation of duties finansial" --
 * staff non-full-access yang men-submit "Proses Referral"/"Proses
 * Refund" TIDAK LAGI langsung memposting ke Jurnal Umum. Permintaan
 * disimpan di sini dulu (status pending), baru dieksekusi beneran
 * (BookingPostingService::sync()/RefundService::process()) setelah
 * user full-access approve. User full-access sendiri TETAP bisa
 * proses langsung tanpa lewat approval (mereka sudah otoritas
 * tertinggi -- self-approval tidak menambah kontrol apa pun).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['booking_referral', 'refund']);
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // Payload nominal yang diajukan -- untuk 'booking_referral':
            // {transaction_amount, amount_received}. Untuk 'refund':
            // {amount, reason}.
            $table->json('payload');

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['booking_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_approval_requests');
    }
};
