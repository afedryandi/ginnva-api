<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FilmProduct;

class FilmProductSeeder extends Seeder
{
    /**
     * Jalankan dengan: php artisan db:seed --class=FilmProductSeeder
     *
     * Strategi upsert (updateOrCreate per SKU) — aman dijalankan ulang
     * tanpa menghapus data terkait di quotation_items / case_studies
     * yang punya FK cascadeOnDelete ke tabel ini.
     *
     * SKU lama (Ziwei series) dihapus secara eksplisit di akhir,
     * tapi HANYA jika belum punya relasi — supaya data historis quotation
     * tidak hilang kalau ada. Kalau mau force-hapus, jalankan
     * php artisan migrate:fresh --seed (reset seluruh DB).
     *
     * Data produk mengacu pada tabel spesifikasi teknis resmi:
     *   - Car Window Film  → seri A70 / H70 / H30 / H15
     *   - PPF              → Black Crystal M8-M / Orange Crystal M10 & H10 / Green Crystal EV7
     *
     * base_price = harga dasar per unit/meter sebelum dikali koefisien
     * dari PriceRule. Sesuaikan di Filament setelah seeder dijalankan.
     */
    public function run(): void
    {
        // ─── Car Window Film ──────────────────────────────────────────
        // Seri A70 — Bi-silver Sputtering, garansi 10 tahun. Nama jual
        // "Ginnva Signature" (dikonfirmasi user 2026-09-10, lihat migrasi
        // 2026_09_10_000006). base_price di sini cuma nilai awal seeder —
        // harga riil (matriks per ukuran) diisi lewat migrasi import +
        // Filament, JANGAN andalkan angka ini.
        FilmProduct::updateOrCreate(
            ['sku' => 'WF-A70'],
            [
                'name'         => 'Ginnva Signature',
                'product_type' => 'window_film',
                'base_price'   => 400000,
                'is_active'    => true,
            ]
        );

        // Seri H70 — Nano-Ceramic, garansi 8 tahun. Nama jual "Ginnva
        // Platinum" (dikonfirmasi user 2026-09-10).
        FilmProduct::updateOrCreate(
            ['sku' => 'WF-H70'],
            [
                'name'         => 'Ginnva Platinum',
                'product_type' => 'window_film',
                'base_price'   => 350000,
                'is_active'    => true,
            ]
        );

        // WF-H30 & WF-H15 DIHAPUS 2026-09-10 (migrasi 2026_09_10_000008) —
        // tidak ada di katalog Majoo sebagai produk standalone (Majoo cuma
        // punya "Panoramic H15/H30" yg sudah dibuat sebagai WF-PANORAMIC*).
        // Jangan tambahkan lagi di sini.

        // ─── Paint Protection Film ────────────────────────────────────
        // Black Crystal M8-M — Matte, 7.5 mil, garansi 8 tahun
        FilmProduct::updateOrCreate(
            ['sku' => 'PPF-BLACK-CRYSTAL-M8M'],
            [
                'name'         => 'Black Crystal M8-M (Matte)',
                'product_type' => 'ppf',
                'base_price'   => 4500000,
                'is_active'    => true,
            ]
        );

        // Orange Crystal M10 — Gloss, 8.8 mil, garansi 8 tahun
        FilmProduct::updateOrCreate(
            ['sku' => 'PPF-ORANGE-CRYSTAL-M10'],
            [
                'name'         => 'Orange Crystal M10 (Gloss)',
                'product_type' => 'ppf',
                'base_price'   => 4200000,
                'is_active'    => true,
            ]
        );

        // Orange Crystal H10 — Gloss, 7.8 mil, garansi 8 tahun
        FilmProduct::updateOrCreate(
            ['sku' => 'PPF-ORANGE-CRYSTAL-H10'],
            [
                'name'         => 'Orange Crystal H10 (Gloss)',
                'product_type' => 'ppf',
                'base_price'   => 4000000,
                'is_active'    => true,
            ]
        );

        // Green Crystal EV7 — Hydrophilic/Gloss, 7.5 mil, garansi 5 tahun
        FilmProduct::updateOrCreate(
            ['sku' => 'PPF-GREEN-CRYSTAL-EV7'],
            [
                'name'         => 'Green Crystal EV7 (Gloss)',
                'product_type' => 'ppf',
                'base_price'   => 3800000,
                'is_active'    => true,
            ]
        );

        // ─── Hapus SKU lama (Ziwei series & nama lama) ───────────────
        // Hanya dihapus jika tidak punya relasi di quotation_items
        // atau case_studies — aman untuk production yang sudah berjalan.
        $obsoleteSkus = [
            'WF-ZIWEI-70',
            'WF-ZIWEI-40',
            'WF-ZIWEI-20',
            'PPF-MATTE',
            'PPF-GLOSSY',
            'CCF-SATIN',
            'CCF-GLOSSY',
        ];

        foreach ($obsoleteSkus as $sku) {
            $product = FilmProduct::where('sku', $sku)->first();
            if (! $product) {
                continue;
            }

            $hasRelations = $product->quotationItems()->exists()
                         || $product->caseStudies()->exists();

            if (! $hasRelations) {
                $product->delete();
                $this->command->info("Dihapus: {$sku}");
            } else {
                $product->update(['is_active' => false]);
                $this->command->warn("Dinonaktifkan (ada relasi): {$sku}");
            }
        }
    }
}