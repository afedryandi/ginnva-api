<?php

namespace Database\Seeders;

use App\Models\EmployeeType;
use Illuminate\Database\Seeder;

/**
 * Gap standar enterprise diperbaiki 2026-09-27 (audit Tipe Karyawan):
 * SEBELUMNYA tabel employee_types kosong total sampai admin isi manual
 * -- form "Tipe Karyawan" User (UserResource) kosong sampai admin sadar
 * harus isi master data ini dulu. firstOrCreate() -- aman dijalankan
 * berkali-kali (idempotent), TIDAK akan duplikat atau menimpa baris yang
 * sudah ada/sudah diubah admin.
 */
class EmployeeTypeSeeder extends Seeder
{
    public function run(): void
    {
        EmployeeType::firstOrCreate(
            ['name' => 'Tetap'],
            ['has_end_date' => false, 'is_active' => true]
        );

        EmployeeType::firstOrCreate(
            ['name' => 'Kontrak (PKWT)'],
            ['has_end_date' => true, 'is_active' => true]
        );
    }
}
