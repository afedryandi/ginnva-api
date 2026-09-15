<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Invoice (diminta user 2026-09-15, analog "Daftar Invoice"
 * Majoo) -- SENGAJA V1 murni dokumen tagihan/cetak, TIDAK posting
 * otomatis ke Jurnal Umum (beda dari Booking/Proses Referral). Bisa
 * dibuat lepas ("Tanpa Nomor Referensi") ATAU referensi 1 Booking
 * yang sudah ada, keduanya opsional -- booking_id nullable.
 *
 * TIDAK ada kolom pajak/PPN sama sekali -- perhitungan PPN masih
 * menunggu keputusan bisnis (lihat memory project_penjualan_majoo_
 * blocked_items.md), jangan tambah placeholder angka pajak palsu di
 * sini. Kalau keputusan PPN sudah turun, tambah lewat migrasi
 * terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique(); // INV/{store_code}/YYMMDD/XXXX

            $table->foreignId('store_id')->constrained()->restrictOnDelete();

            // Opsional -- invoice bisa berdiri sendiri ("Tanpa Nomor
            // Referensi" ala Majoo) atau referensi booking yang sudah ada.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();

            // customer_id opsional (customer yang login app) + kolom
            // teks bebas untuk nama/kontak -- sama pola dengan Booking
            // (customer_name/phone_number didenormalisasi) supaya invoice
            // tetap bisa dibuat untuk pelanggan yang belum/tidak punya
            // akun customer.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->string('customer_email')->nullable();

            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();

            $table->date('issue_date');
            $table->date('due_date')->nullable();

            $table->enum('status', ['draft', 'unpaid', 'paid', 'void'])->default('draft');

            // Rincian nominal -- SEMUA pre-tax, tidak ada kolom pajak
            // (lihat catatan class di atas).
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('product_discount', 15, 2)->default(0);
            $table->string('transaction_discount_type', 10)->nullable(); // 'rp' | 'percent'
            $table->decimal('transaction_discount_value', 15, 2)->default(0);
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->decimal('other_cost', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);

            $table->text('notes')->nullable();
            $table->text('terms_conditions')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index('issue_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
