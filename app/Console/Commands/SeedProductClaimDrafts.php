<?php

namespace App\Console\Commands;

use App\Models\ProductClaim;
use Illuminate\Console\Command;

/**
 * Memasukkan angka yang SAAT INI tertulis di halaman kampanye kaca film (web) sebagai klaim DRAFT (Marketing/Konten Fase 2), supaya
 * penyetuju tidak mengetik ulang dari nol. Semuanya berstatus draft tanpa bukti: tidak ada yang tayang dari tabel ini sampai
 * seseorang melampirkan bukti dan menyetujuinya. Aman dijalankan berulang (tidak menggandakan).
 */
class SeedProductClaimDrafts extends Command
{
    protected $signature = 'claims:seed-drafts';

    protected $description = 'Masukkan angka klaim yang kini tertulis di halaman kampanye kaca film sebagai draft (belum disetujui)';

    /** series => [[jenis, label, nilai], ...] sesuai app/kaca-film/campaign/page.tsx (SERIES) di ginnva-web. */
    private const CURRENT_SITE_CLAIMS = [
        'A70' => [['vlt', 'VLT', '72%'], ['tser', 'TSER', '61%'], ['uv', 'UV Blocked', '99%'], ['warranty_years', 'Garansi', '10 tahun']],
        'H70' => [['tser', 'TSER', '47%'], ['uv', 'UV Blocked', '99%'], ['warranty_years', 'Garansi', '8 tahun']],
        'H30' => [['vlt', 'VLT', '28%'], ['tser', 'TSER', '56%'], ['uv', 'UV Blocked', '99%'], ['warranty_years', 'Garansi', '8 tahun']],
        'H15' => [['vlt', 'VLT', '16%'], ['tser', 'TSER', '65%'], ['uv', 'UV Blocked', '99%'], ['warranty_years', 'Garansi', '8 tahun']],
    ];

    public function handle(): int
    {
        $created = 0;

        foreach (self::CURRENT_SITE_CLAIMS as $series => $claims) {
            foreach ($claims as [$type, $label, $value]) {
                $claim = ProductClaim::firstOrCreate(
                    ['series' => $series, 'claim_type' => $type],
                    ['label' => $label, 'value' => $value, 'status' => 'draft'],
                );

                $created += $claim->wasRecentlyCreated ? 1 : 0;
            }
        }

        $this->info("{$created} klaim draft dibuat. Lampirkan bukti lalu setujui di panel Marketing/Konten > Klaim & Bukti.");

        return self::SUCCESS;
    }
}
