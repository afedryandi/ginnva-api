<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number')->unique(); // QTN-202606-0001
            $table->foreignId('vehicle_id')->constrained('vehicles')->onDelete('restrict');
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->string('license_plate')->nullable(); // Nomor polisi
            $table->decimal('total_price', 15, 2)->default(0);
            $table->enum('status', ['draft', 'sent', 'approved', 'rejected'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};