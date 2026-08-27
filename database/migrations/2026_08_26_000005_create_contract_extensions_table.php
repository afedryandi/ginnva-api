<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat perpanjangan kontrak — users.contract_end_date SELALU snapshot
 * tanggal kontrak AKTIF saat ini (dipakai NotifyExpiringContracts &
 * ditampilkan di UserResource), baris di sini adalah JEJAK tiap kali
 * kontrak diperpanjang (lihat ContractExtension::recordExtension()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('previous_end_date')->nullable();
            $table->date('new_end_date');
            $table->text('notes')->nullable();
            $table->foreignId('extended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_extensions');
    }
};
