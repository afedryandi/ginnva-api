<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uang Muka (DP) booking -- keputusan atasan 2026-09-19 (Topik 2,
 * "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx"): DP BUKAN syarat wajib
 * saat booking, diterima FLEKSIBEL kapan saja selama proses instalasi
 * berjalan, dicatat MANUAL oleh staff saat uang benar-benar diterima.
 * Nominal bebas diisi staff per booking (tidak ada aturan tetap/
 * persentase baku). Lihat DownPaymentService.
 *
 * Akuntansi: DP BELUM boleh dianggap "pendapatan" sampai jasanya
 * diberikan (lihat catatan "Kenapa ini penting" di dokumen keputusan)
 * -- setiap DP diterima WAJIB posting jurnal Debit Kas / Kredit akun
 * 2140 "Pendapatan Diterima Dimuka" (sudah ada di ChartOfAccountSeeder),
 * BUKAN akun pendapatan (4100/4200) seperti transaction_amount biasa.
 *
 * 1 booking BISA punya banyak baris DP (dibayar bertahap) -- makanya
 * tabel terpisah (hasMany), bukan 1 kolom di bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_down_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('received_at');
            $table->text('notes')->nullable();

            // Jurnal saat DP DITERIMA (Debit Kas / Kredit 2140).
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            // Jurnal PEMBALIK saat DP dikembalikan -- keputusan atasan:
            // "dikembalikan penuh" (sementara, bisa direvisi nanti). Null
            // selama belum dikembalikan; diisi begitu booking terkait
            // dibatalkan (lihat DownPaymentService::refundAllOnCancellation()).
            $table->foreignId('refund_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_down_payments');
    }
};
