<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom `stage` semula enum dengan 5 nilai flat (received/preparation/
 * installation/qc/completed) — sejak booking dibedakan jadi track Kaca
 * Film & PPF (lihat BookingMessage::PRODUCT_STAGES), key stage jadi lebih
 * banyak dan berbeda (kf_cleaning, ppf_washing, dst). Diwidenkan jadi
 * string biasa supaya tidak perlu migration enum setiap kali tahap
 * berubah lagi di masa depan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_messages', function (Blueprint $table) {
            $table->string('stage', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('booking_messages', function (Blueprint $table) {
            $table->enum('stage', ['received', 'preparation', 'installation', 'qc', 'completed'])->nullable()->change();
        });
    }
};
