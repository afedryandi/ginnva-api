<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Profil karyawan HRIS lengkap" (audit Majoo, f54), dibangun
 * 2026-09-22 atas keputusan user. Semua field OPSIONAL/nullable --
 * data personalia formal ini diisi bertahap, tidak wajib lengkap
 * langsung. Data sensitif (NIK/NPWP/BPJS/rekening) — akses form
 * dibatasi full-access saja di UserResource, sama filosofi dengan
 * commission_amount/base_salary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nik', 20)->nullable()->after('employee_type_id');
            $table->string('npwp', 25)->nullable()->after('nik');
            $table->string('bpjs_kesehatan_number', 20)->nullable()->after('npwp');
            $table->string('bpjs_ketenagakerjaan_number', 20)->nullable()->after('bpjs_kesehatan_number');
            $table->string('emergency_contact_name')->nullable()->after('bpjs_ketenagakerjaan_number');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_relationship')->nullable()->after('emergency_contact_phone');
            $table->string('bank_name')->nullable()->after('emergency_contact_relationship');
            $table->string('bank_account_number')->nullable()->after('bank_name');
            $table->string('bank_account_holder_name')->nullable()->after('bank_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'nik', 'npwp', 'bpjs_kesehatan_number', 'bpjs_ketenagakerjaan_number',
                'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
                'bank_name', 'bank_account_number', 'bank_account_holder_name',
            ]);
        });
    }
};
