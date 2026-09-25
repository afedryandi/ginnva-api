<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat kunjungan maintenance (fitur "Kuota Maintenance", 2026-09-25) --
 * staff catat lewat WarrantyResource::performRecordMaintenanceVisit() tiap
 * kali customer datang walk-in ke toko untuk maintenance. Baris ini
 * riwayat murni -- tidak pernah diedit/dihapus lewat UI, sama pola dengan
 * WarrantyOwnershipTransfer. Sisa kuota SELALU dihitung
 * (warranty.maintenance_quota - COUNT baris ini), bukan kolom counter
 * terpisah yang bisa drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_maintenance_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warranty_id')->constrained()->cascadeOnDelete();
            $table->date('visited_at');
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_maintenance_visits');
    }
};
