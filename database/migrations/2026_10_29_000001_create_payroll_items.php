<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Komponen gaji (Karyawan Fase 1, 2026-10-29): tunjangan, bonus, THR, uang kompensasi, serta potongan BPJS/PPh 21/kasbon yang angkanya
 * dihitung akuntan di luar sistem dan hanya DICATAT di slip. Tiap komponen punya kategori; kategori menentukan jenis (tambahan atau
 * potongan) dan akun pembukuan (bisa diubah akuntan di panel). Kolom baru di payrolls menyimpan jumlah tambahan dan potongan komponen,
 * bawaan 0 supaya baris lama tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_item_categories')) {
            Schema::create('payroll_item_categories', function (Blueprint $table) {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('name', 120);
                $table->string('type', 10);                                  // earning | deduction
                $table->foreignId('chart_of_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
                $table->boolean('is_recurring_default')->default(false);     // bawaan "ikut disalin ke bulan berikutnya"
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payroll_items')) {
            Schema::create('payroll_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payroll_id')->constrained()->cascadeOnDelete();
                $table->foreignId('payroll_item_category_id')->constrained('payroll_item_categories');
                $table->string('label', 120)->nullable();                    // bila kosong memakai nama kategori
                $table->decimal('amount', 14, 2);
                $table->boolean('is_recurring')->default(false);
                $table->string('notes', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('payroll_id');
            });
        }

        Schema::table('payrolls', function (Blueprint $table) {
            if (! Schema::hasColumn('payrolls', 'total_additions')) {
                $table->decimal('total_additions', 14, 2)->default(0);
            }
            if (! Schema::hasColumn('payrolls', 'total_item_deductions')) {
                $table->decimal('total_item_deductions', 14, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            foreach (['total_additions', 'total_item_deductions'] as $column) {
                if (Schema::hasColumn('payrolls', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_item_categories');
    }
};
