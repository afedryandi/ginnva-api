<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog layanan booking (Fase 2 pengerjaan ulang booking, 2026-10-16): nama, deskripsi, kategori kapasitas (berat/ringan),
 * lama pengerjaan, urutan, dan aktif/tidak untuk empat layanan yang bisa dipesan, supaya admin bisa mengubahnya dari
 * Filament (menu Booking → Layanan Booking) tanpa developer. Empat kunci (kaca_film, ppf, detailing, premium_wash) tetap
 * karena tiap kunci terhubung ke kolom penanda produk di tabel bookings, jadi tidak ada tambah/hapus layanan dari admin.
 * Kategori kapasitas: "berat" memakai kapasitas harian toko; "ringan" memakai slot jam (keputusan pemilik 2026-10-15).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_service_types')) {
            Schema::create('booking_service_types', function (Blueprint $table) {
                $table->id();
                $table->string('key', 30)->unique();
                $table->string('label', 80);
                $table->string('description', 255)->nullable();
                $table->enum('capacity_category', ['berat', 'ringan']);
                $table->unsignedTinyInteger('estimated_duration_days')->default(1);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        $defaults = [
            ['key' => 'kaca_film', 'label' => 'Kaca Film (Window Film)', 'description' => 'Kaca film untuk kenyamanan, privasi, dan penolak panas.', 'capacity_category' => 'berat', 'estimated_duration_days' => 1, 'sort_order' => 1],
            ['key' => 'ppf', 'label' => 'Pelindung Cat (PPF)', 'description' => 'Lapisan pelindung cat dari baret, goresan halus, dan batu kerikil.', 'capacity_category' => 'berat', 'estimated_duration_days' => 3, 'sort_order' => 2],
            ['key' => 'detailing', 'label' => 'Detailing', 'description' => 'Perawatan menyeluruh interior dan eksterior.', 'capacity_category' => 'ringan', 'estimated_duration_days' => 1, 'sort_order' => 3],
            ['key' => 'premium_wash', 'label' => 'Premium Wash', 'description' => 'Cuci premium dengan perawatan detail.', 'capacity_category' => 'ringan', 'estimated_duration_days' => 1, 'sort_order' => 4],
        ];

        foreach ($defaults as $row) {
            if (! DB::table('booking_service_types')->where('key', $row['key'])->exists()) {
                DB::table('booking_service_types')->insert($row + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_service_types');
    }
};
