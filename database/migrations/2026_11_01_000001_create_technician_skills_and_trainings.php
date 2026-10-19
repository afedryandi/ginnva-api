<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Karyawan Fase 4 (2026-11-01): kompetensi installer.
 *  - technician_skills: matriks keterampilan per layanan (PPF, kaca film, detailing, premium wash) dengan tingkat 1-3 dan tanggal dinilai;
 *  - technician_trainings: pelatihan dan sertifikasi (internal/produsen) beserta masa berlakunya dan berkas sertifikat privat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technician_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->string('service_type', 30);
            $table->unsignedTinyInteger('level'); // 1 pemula (didampingi), 2 mandiri, 3 mahir (boleh melatih)
            $table->date('assessed_on');
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['technician_id', 'service_type']);
        });

        Schema::create('technician_trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technician_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('provider', 160)->nullable();
            $table->string('service_type', 30)->nullable();
            $table->date('held_on');
            $table->date('valid_until')->nullable();
            $table->string('certificate_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['technician_id', 'held_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_trainings');
        Schema::dropIfExists('technician_skills');
    }
};
