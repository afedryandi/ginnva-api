<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redesain kapasitas instalasi per tanggal (audit Booking Instalasi
 * 2026-09-25, diminta user langsung) — SEBELUMNYA "Kapasitas Instalasi
 * per Tanggal" di form approve booking (BookingResource) TIDAK PERNAH
 * disimpan ke mana pun: staff mengetik ulang angka kapasitas dari nol
 * SETIAP kali approve booking yang menyentuh tanggal itu (defaultnya
 * selalu install_capacity_per_day toko, tapi bisa diedit bebas tiap
 * kali tanpa sistem menegur inkonsistensi). Staff A bisa approve
 * booking dengan kapasitas 9 utk 25 Sep, staff B approve booking lain
 * di tanggal sama dengan kapasitas 3 -- tidak ada satu sumber
 * kebenaran per tanggal.
 *
 * Tabel ini jadi SATU sumber kebenaran opsional: baris ADA = kapasitas
 * tanggal itu di-override manual (mis. installer izin, hari spesial),
 * baris TIDAK ADA = pakai install_capacity_per_day toko apa adanya.
 * Dikelola lewat kalender kapasitas (bukan lagi diketik ulang di form
 * approve tiap booking) -- lihat App\Filament\Pages\CapacityCalendar &
 * Booking::capacityForDate().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_capacity_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('capacity');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_capacity_overrides');
    }
};
