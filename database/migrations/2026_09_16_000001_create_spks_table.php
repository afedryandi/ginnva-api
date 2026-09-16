<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digitalisasi form kertas "Surat Perintah Kerja" (SPK) Ginnva --
 * diminta user 2026-09-16. V1 murni dokumen isi + cetak PDF, ANALOG
 * pola Invoice (lihat create_invoices_table): tidak menyentuh posting
 * jurnal/keuangan sama sekali.
 *
 * SENGAJA required + unique ke booking_id (beda dari MaterialMemo yang
 * nullable) -- SPK di dunia nyata SELALU untuk 1 pekerjaan/booking
 * yang sudah pasti ada, tidak ada konsep "SPK tanpa referensi".
 *
 * BELUM ada di V1 (disepakati bertahap): diagram kondisi kendaraan
 * dengan kode kerusakan (C/B/P/G/M/OS) dan tanda tangan digital 4 pihak
 * (Dikerjakan/Diperiksa/Konfirmasi Pelanggan/Diterima) -- di form
 * kertas cukup dicetak sebagai kotak kosong dulu, sama seperti sebelum
 * didigitalisasi. Kalau dilanjutkan, rencananya masing-masing jadi
 * tabel baru (spk_damage_marks, spk_approvals) supaya tidak mengubah
 * skema ini lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spks', function (Blueprint $table) {
            $table->id();
            $table->string('spk_number')->unique(); // SPK/{store_code}/YYMMDD/XXXX

            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();

            // Snapshot data pelanggan saat SPK dibuat -- BUKAN relasi
            // live ke Booking/Customer, supaya cetakan lama tidak ikut
            // berubah kalau data pelanggan diedit belakangan (sama
            // filosofi dengan Invoice::customer_name/customer_phone).
            $table->string('customer_name');
            $table->string('phone_number')->nullable();
            $table->text('address')->nullable();

            // Data Kendaraan -- sebagian field baru (tidak ada padanan
            // di Warranty::car_plate/car_type/vin, dipakai nama kolom
            // konsisten di sini untuk kejelasan form).
            $table->string('vehicle_plate')->nullable();
            $table->string('vehicle_vin')->nullable(); // No. Rangka
            $table->string('vehicle_brand')->nullable(); // Merek
            $table->string('vehicle_year', 4)->nullable();
            $table->enum('vehicle_type', ['sedan', 'suv', 'mpv', 'jeep'])->nullable();
            $table->unsignedInteger('vehicle_km')->nullable();
            $table->enum('fuel_level', ['e', 'quarter', 'half', 'three_quarter', 'f'])->nullable();
            $table->string('battery_note')->nullable();

            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spks');
    }
};
