<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sama seperti booking_messages.stage (lihat migration widen_booking_messages_
 * stage_column) — `current_stage` semula enum 5 nilai flat, tapi sejak
 * dual-track Kaca Film/PPF (Booking::stageColumnFor()) key stage-nya jadi
 * kf_cleaning/kf_heating/kf_installation/ppf_washing/ppf_detailing/
 * ppf_installation/qc/completed. Diwidenkan jadi string biasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('current_stage', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('current_stage', ['received', 'preparation', 'installation', 'qc', 'completed'])->nullable()->change();
        });
    }
};
