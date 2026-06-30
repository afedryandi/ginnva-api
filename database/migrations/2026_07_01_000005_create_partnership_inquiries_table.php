<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pengajuan kemitraan/franchise (我要加盟). TIDAK wajib login — ini
     * lebih mirip lead generation (mirip ProductInquiry), siapapun yang
     * tertarik jadi mitra bisa isi form tanpa harus daftar akun dulu.
     * customer_id nullable: kalau yang isi sedang login, otomatis
     * tercatat; kalau tidak, tetap bisa submit sebagai guest.
     */
    public function up(): void
    {
        Schema::create('partnership_inquiries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();

            $table->string('applicant_name');
            $table->string('phone_number');
            $table->string('email')->nullable();
            $table->string('city');
            $table->text('message')->nullable();

            $table->enum('status', ['new', 'contacted', 'rejected'])->default('new');
            $table->text('notes')->nullable(); // catatan internal sales

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partnership_inquiries');
    }
};
