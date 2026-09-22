<?php

namespace App\Services;

use App\Models\FilmProduct;
use App\Models\ProductImportLog;
use Maatwebsite\Excel\Facades\Excel;

/**
 * "Impor bulk data produk" (audit Majoo, f27) — BEDA dari "Ekspor
 * Laporan" (gap terpisah, itu untuk laporan read-only): ini untuk
 * bulk-UPDATE katalog produk yang SUDAH ADA, mis. update harga massal
 * setelah supplier naikkan harga bahan.
 *
 * SENGAJA cuma UPDATE, tidak pernah membuat produk baru — SKU yang
 * tidak ditemukan di katalog dilewati & dicatat sebagai error, bukan
 * diam-diam dibuat jadi produk baru (mencegah typo SKU menciptakan
 * produk sampah). Kolom yang kosong di baris file TIDAK mengosongkan
 * data yang sudah ada (partial update per kolom), supaya staff bisa
 * kirim ulang file yang cuma isi harga tanpa berisiko menghapus nama.
 */
class ProductBulkImportService
{
    public function importFromFile(string $absolutePath, string $originalFilename, ?int $userId): ProductImportLog
    {
        $sheets = Excel::toArray(null, $absolutePath);
        $rows = $sheets[0] ?? [];
        array_shift($rows); // baris pertama = header

        $updatedCount = 0;
        $skippedCount = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $lineNumber = $i + 2; // +1 balik ke 1-based, +1 lagi krn header sudah dibuang

            $sku = isset($row[0]) ? trim((string) $row[0]) : '';
            if ($sku === '') {
                $skippedCount++;
                $errors[] = "Baris {$lineNumber}: SKU kosong, dilewati.";

                continue;
            }

            $product = FilmProduct::where('sku', $sku)->first();
            if (! $product) {
                $skippedCount++;
                $errors[] = "Baris {$lineNumber}: SKU \"{$sku}\" tidak ditemukan di katalog — import ini tidak membuat produk baru.";

                continue;
            }

            $update = [];

            $name = isset($row[1]) ? trim((string) $row[1]) : '';
            if ($name !== '') {
                $update['name'] = $name;
            }

            $priceRaw = isset($row[2]) ? trim((string) $row[2]) : '';
            if ($priceRaw !== '') {
                $price = (float) str_replace(['Rp', '.', ',', ' '], ['', '', '.', ''], $priceRaw);
                if ($price < 0) {
                    $errors[] = "Baris {$lineNumber}: harga \"{$priceRaw}\" tidak valid, kolom harga dilewati (kolom lain di baris ini tetap diproses).";
                } else {
                    $update['base_price'] = $price;
                }
            }

            $activeRaw = isset($row[3]) ? strtoupper(trim((string) $row[3])) : '';
            if ($activeRaw !== '') {
                $update['is_active'] = in_array($activeRaw, ['Y', 'YA', '1', 'AKTIF', 'TRUE'], true);
            }

            if (empty($update)) {
                $skippedCount++;
                $errors[] = "Baris {$lineNumber}: SKU \"{$sku}\" tidak punya kolom yang diisi, tidak ada yang diubah.";

                continue;
            }

            $product->update($update);
            $updatedCount++;
        }

        return ProductImportLog::create([
            'user_id' => $userId,
            'filename' => $originalFilename,
            'total_rows' => count($rows),
            'updated_count' => $updatedCount,
            'skipped_count' => $skippedCount,
            // Cap 100 pesan -- file dengan ribuan error tidak perlu
            // menyimpan semuanya, cukup sampel yang cukup untuk staff
            // tahu pola masalahnya.
            'errors' => array_slice($errors, 0, 100),
        ]);
    }
}
