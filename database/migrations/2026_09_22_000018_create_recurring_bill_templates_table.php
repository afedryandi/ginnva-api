<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Template tagihan rutin yg auto-generate Biaya/AP secara berkala"
 * (audit Majoo, f48), dibangun 2026-09-22 atas keputusan user.
 *
 * `next_run_date` = tanggal berikutnya template ini harus generate
 * Payable baru -- dimajukan 1 bulan (clamp ke akhir bulan kalau
 * day_of_month > jumlah hari bulan itu) setiap kali dijalankan
 * (lihat RecurringBillGenerationService). Frekuensi SENGAJA hanya
 * bulanan (biaya rutin khas: sewa, langganan software) -- bukan
 * sistem RRULE kompleks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_bill_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('supplier_name');
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chart_of_account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->unsignedTinyInteger('day_of_month');
            $table->date('next_run_date');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_bill_templates');
    }
};
