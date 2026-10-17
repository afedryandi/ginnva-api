<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aturan potongan absensi (Karyawan Fase 0, keputusan pemilik 2026-10-17): potongan telat per blok penuh menit dan potongan Alpha
 * per jam kerja ditambah denda tetap per hari. Semua nominal diisi staf lewat panel (tabel aturan dengan tanggal berlaku, jadi riwayat
 * aturan terjaga), bukan ditulis di kode. Kolom baru di payrolls menyimpan rincian yang dipakai; semuanya nullable supaya baris lama
 * tetap dibaca seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_deduction_rules')) {
            Schema::create('attendance_deduction_rules', function (Blueprint $table) {
                $table->id();
                $table->date('effective_from')->unique();                      // berlaku untuk payroll bulan yang dimulai pada/setelah tanggal ini
                $table->unsignedSmallInteger('late_block_minutes')->default(15);
                $table->decimal('late_deduction_per_block', 14, 2)->default(0);
                $table->decimal('alpha_fine_per_day', 14, 2)->default(0);
                $table->string('notes', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        Schema::table('payrolls', function (Blueprint $table) {
            if (! Schema::hasColumn('payrolls', 'late_blocks')) {
                $table->unsignedInteger('late_blocks')->nullable();
            }
            if (! Schema::hasColumn('payrolls', 'late_block_minutes')) {
                $table->unsignedSmallInteger('late_block_minutes')->nullable();
            }
            if (! Schema::hasColumn('payrolls', 'deduction_per_block')) {
                $table->decimal('deduction_per_block', 14, 2)->nullable();
            }
            if (! Schema::hasColumn('payrolls', 'late_deduction')) {
                $table->decimal('late_deduction', 14, 2)->nullable();
            }
            if (! Schema::hasColumn('payrolls', 'scheduled_hours_in_month')) {
                $table->decimal('scheduled_hours_in_month', 8, 2)->nullable();
            }
            if (! Schema::hasColumn('payrolls', 'hourly_rate')) {
                $table->decimal('hourly_rate', 14, 2)->nullable();
            }
            if (! Schema::hasColumn('payrolls', 'alpha_hours')) {
                $table->decimal('alpha_hours', 8, 2)->nullable();
            }
            if (! Schema::hasColumn('payrolls', 'alpha_fine')) {
                $table->decimal('alpha_fine', 14, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            foreach (['late_blocks', 'late_block_minutes', 'deduction_per_block', 'late_deduction', 'scheduled_hours_in_month', 'hourly_rate', 'alpha_hours', 'alpha_fine'] as $column) {
                if (Schema::hasColumn('payrolls', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('attendance_deduction_rules');
    }
};
