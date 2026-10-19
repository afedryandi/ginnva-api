<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Karyawan Fase 3 (2026-10-31): siklus kepegawaian dan Peraturan Perusahaan.
 *  - company_policies + policy_acknowledgments: dokumen Peraturan Perusahaan/kebijakan/SOP dan bukti tiap karyawan menerimanya
 *    (dasar sah potongan telat dan Alpha);
 *  - checklist_templates + employee_checklist_items: daftar butir onboarding/offboarding dan statusnya per karyawan;
 *  - salary_grades + users.salary_grade_id: struktur dan skala upah (rentang gaji pokok per golongan/jabatan);
 *  - users.probation_end_date: akhir masa percobaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_policies', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->string('category', 30); // peraturan_perusahaan | kebijakan | sop
            $table->string('version', 40)->nullable();
            $table->date('effective_from')->nullable();
            $table->text('description')->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->boolean('requires_acknowledgment')->default(true);
            $table->boolean('is_published')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('policy_acknowledgments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('acknowledged_at');
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->unique(['company_policy_id', 'user_id']);
        });

        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12); // onboarding | offboarding
            $table->string('title', 200);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['kind', 'title']);
        });

        Schema::create('employee_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);
            $table->string('title', 200);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->boolean('is_done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'kind', 'title']);
            $table->index(['kind', 'is_done']);
        });

        Schema::create('salary_grades', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->decimal('min_salary', 15, 2);
            $table->decimal('max_salary', 15, 2);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->date('probation_end_date')->nullable()->after('contract_end_date');
            $table->foreignId('salary_grade_id')->nullable()->after('base_salary')->constrained('salary_grades')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salary_grade_id');
            $table->dropColumn('probation_end_date');
        });

        Schema::dropIfExists('salary_grades');
        Schema::dropIfExists('employee_checklist_items');
        Schema::dropIfExists('checklist_templates');
        Schema::dropIfExists('policy_acknowledgments');
        Schema::dropIfExists('company_policies');
    }
};
