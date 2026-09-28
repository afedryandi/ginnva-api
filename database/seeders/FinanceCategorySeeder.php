<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\FinanceCategory;
use Illuminate\Database\Seeder;

/**
 * Kategori Keuangan default (audit Kategori Keuangan 2026-09-28) --
 * dipetakan ke akun Bagan Akun yang sudah di-seed ChartOfAccountSeeder.
 *
 * AMAN dijalankan di production yang kategorinya sudah diisi manual:
 * - kategori dianggap sudah ada kalau nama yang sama (tanpa membedakan huruf
 *   besar/kecil) sudah ada di tipe yang sama -> DILEWATI, tidak pernah
 *   ditimpa atau digandakan;
 * - kategori yang akunnya tidak ditemukan (kode akun belum ada) dilewati,
 *   bukan dibuat tanpa akun;
 * - grup dibuat hanya kalau minimal satu anaknya berhasil dibuat.
 * Jalankan manual: php artisan db:seed --class=FinanceCategorySeeder
 */
class FinanceCategorySeeder extends Seeder
{
    private const DEFAULTS = [
        ['type' => 'out', 'group' => 'Beban Karyawan', 'children' => [
            'Tunjangan & BPJS' => '6130',
            'Bonus & Insentif' => '6140',
        ]],
        ['type' => 'out', 'group' => 'Beban Toko', 'children' => [
            'Sewa Toko' => '6210',
            'Listrik & Air' => '6220',
            'Internet & Telepon' => '6230',
            'Kebersihan & Keamanan' => '6240',
        ]],
        ['type' => 'out', 'group' => 'Beban Pemasaran', 'children' => [
            'Iklan & Promosi' => '6310',
        ]],
        ['type' => 'out', 'group' => 'Beban Administrasi & Umum', 'children' => [
            'ATK & Perlengkapan Kantor' => '6410',
            'Pemeliharaan & Perbaikan' => '6430',
            'Transportasi & Perjalanan Dinas' => '6440',
            'Asuransi' => '6450',
            'Legal & Perizinan' => '6460',
            'Sistem & Software' => '6470',
        ]],
        ['type' => 'in', 'group' => null, 'children' => [
            'Bunga Bank' => '7100',
            'Pendapatan Lain-lain' => '4400',
        ]],
    ];

    public function run(): void
    {
        $order = (int) (FinanceCategory::max('sort_order') ?? 0);

        foreach (self::DEFAULTS as $block) {
            $type = $block['type'];
            $parentId = null;

            foreach ($block['children'] as $name => $accountCode) {
                if ($this->exists($type, $name)) {
                    continue;
                }

                $accountId = ChartOfAccount::where('code', $accountCode)->value('id');
                if (! $accountId) {
                    continue;
                }

                if ($block['group'] && $parentId === null) {
                    $group = FinanceCategory::whereRaw('LOWER(name) = ?', [mb_strtolower($block['group'])])
                        ->where('type', $type)->where('is_group', true)->first();

                    $parentId = ($group ?? FinanceCategory::create([
                        'name' => $block['group'],
                        'type' => $type,
                        'is_group' => true,
                        'sort_order' => ++$order,
                        'is_active' => true,
                    ]))->id;
                }

                FinanceCategory::create([
                    'name' => $name,
                    'type' => $type,
                    'is_group' => false,
                    'parent_id' => $parentId,
                    'chart_of_account_id' => $accountId,
                    'sort_order' => ++$order,
                    'is_active' => true,
                ]);
            }
        }
    }

    private function exists(string $type, string $name): bool
    {
        return FinanceCategory::where('type', $type)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
    }
}
