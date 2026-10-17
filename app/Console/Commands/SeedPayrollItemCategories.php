<?php

namespace App\Console\Commands;

use App\Models\PayrollItemCategory;
use Illuminate\Console\Command;

/**
 * Membuat kategori komponen gaji bawaan dan akun pembukuan barunya (Beban Tunjangan & Bonus, Beban THR, Beban Kompensasi Kontrak, Hutang BPJS,
 * Hutang Potongan Lainnya) bila belum ada (Karyawan Fase 1, 2026-10-29). Tidak menimpa akun atau kategori yang sudah ada. Aman dijalankan berulang.
 */
class SeedPayrollItemCategories extends Command
{
    protected $signature = 'payroll:seed-item-categories';

    protected $description = 'Buat kategori komponen gaji bawaan dan akun pembukuan barunya (idempoten)';

    public function handle(): int
    {
        $before = PayrollItemCategory::count();
        PayrollItemCategory::ensureDefaults();
        $created = PayrollItemCategory::count() - $before;

        $this->info("{$created} kategori baru dibuat, total " . PayrollItemCategory::count() . '. Periksa akunnya di menu Karyawan > Kategori Komponen Gaji.');

        return self::SUCCESS;
    }
}
