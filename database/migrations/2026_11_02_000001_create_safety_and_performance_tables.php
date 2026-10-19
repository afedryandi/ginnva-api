<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Karyawan Fase 5 (2026-11-02): K3 dan kinerja.
 *  - ppe_types + ppe_issuances: jenis alat pelindung diri (APD) dan catatan pembagiannya per karyawan beserta jatuh tempo penggantian;
 *  - employee_trainings: pelatihan karyawan (K3, produk, dll.) dengan masa berlaku, untuk semua karyawan (installer sudah punya
 *    technician_trainings tersendiri);
 *  - safety_incidents: kejadian K3 (nyaris celaka, cedera, kerusakan) beserta tindak lanjut dan statusnya;
 *  - performance_reviews: penilaian berkala dengan empat aspek skor 1-5, untuk pembinaan (bukan dasar gaji).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppe_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->unsignedSmallInteger('replace_after_months')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ppe_issuances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ppe_type_id')->constrained('ppe_types');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->date('issued_on');
            $table->date('replace_due_on')->nullable();
            $table->date('returned_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'returned_on']);
        });

        Schema::create('employee_trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 20)->default('k3'); // k3 | produk | lainnya
            $table->string('title', 160);
            $table->string('provider', 160)->nullable();
            $table->date('held_on');
            $table->date('valid_until')->nullable();
            $table->string('certificate_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'category']);
        });

        Schema::create('safety_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained();
            $table->dateTime('occurred_at');
            $table->string('type', 20); // near_miss | injury | property_damage | other
            $table->string('severity', 10); // ringan | sedang | berat
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // karyawan yang terlibat/terdampak
            $table->text('description');
            $table->text('immediate_action')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('follow_up')->nullable();
            $table->string('status', 10)->default('open'); // open | closed
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'status']);
        });

        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period_label', 60);
            $table->date('reviewed_on');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('score_quality');
            $table->unsignedTinyInteger('score_discipline');
            $table->unsignedTinyInteger('score_teamwork');
            $table->unsignedTinyInteger('score_initiative');
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->text('goals')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period_label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_reviews');
        Schema::dropIfExists('safety_incidents');
        Schema::dropIfExists('employee_trainings');
        Schema::dropIfExists('ppe_issuances');
        Schema::dropIfExists('ppe_types');
    }
};
