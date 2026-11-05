<?php

namespace App\Services;

use App\Models\FilmProduct;
use App\Models\ProductImportLog;
use Illuminate\Support\Facades\DB;
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
    /** Maksimal perubahan yang disimpan di riwayat satu impor. */
    private const MAX_CHANGES = 300;

    public const SIZE_PRICE_NOTE = 'Produk ini punya harga per ukuran yang menang atas harga dasar, jadi harga jual per ukuran tidak berubah.';

    public function importFromFile(string $absolutePath, string $originalFilename, ?int $userId): ProductImportLog
    {
        $sheets = Excel::toArray(null, $absolutePath);
        $rows = $sheets[0] ?? [];
        array_shift($rows); // baris pertama = header

        $updatedCount = 0;
        $skippedCount = 0;
        $errors = [];
        $changes = [];
        $rp = fn (float $n) => 'Rp' . number_format($n, 0, ',', '.');

        // Seluruh loop update dibungkus transaction: exception di tengah proses membatalkan semua update di file itu (termasuk yang sudah
        // sukses sebelumnya), jadi tidak pernah ada harga berubah tanpa riwayat impor.
        return DB::transaction(function () use ($rows, $userId, $originalFilename, $rp, &$updatedCount, &$skippedCount, &$errors, &$changes) {
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
                    $price = $this->parsePrice($row[2]);

                    // Batas atas menahan harga salah baca; teks yang bukan angka tidak lagi diam-diam menjadi Rp0.
                    if ($price === null || $price < 0 || $price > 1_000_000_000) {
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

                // Perubahan nyata (nilai lama dan baru) untuk riwayat. Harga dasar yang diubah pada produk berharga per ukuran diberi catatan,
                // karena harga per ukuran selalu menang sehingga harga jualnya tidak berubah.
                $hasSizePrices = array_key_exists('base_price', $update) && $product->prices()->exists();
                $before = ['name' => $product->name, 'base_price' => (float) $product->base_price, 'is_active' => (bool) $product->is_active];

                foreach ($update as $field => $new) {
                    $unchanged = $field === 'base_price' ? abs($before[$field] - (float) $new) < 0.005 : $before[$field] === $new;

                    if ($unchanged) {
                        continue;
                    }

                    if (count($changes) < self::MAX_CHANGES) {
                        $changes[] = [
                            'sku' => $sku,
                            'product' => $before['name'],
                            'field' => ['name' => 'Nama Produk', 'base_price' => 'Harga Dasar', 'is_active' => 'Status'][$field],
                            'old' => match ($field) {
                                'base_price' => $rp($before[$field]),
                                'is_active' => $before[$field] ? 'Aktif' : 'Nonaktif',
                                default => $before[$field],
                            },
                            'new' => match ($field) {
                                'base_price' => $rp((float) $new),
                                'is_active' => $new ? 'Aktif' : 'Nonaktif',
                                default => $new,
                            },
                            'note' => $field === 'base_price' && $hasSizePrices ? self::SIZE_PRICE_NOTE : null,
                        ];
                    }
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
                // Cap 100 pesan -- file dengan ribuan error tidak perlu menyimpan semuanya, cukup sampel yang cukup untuk
                // staff tahu pola masalahnya.
                'errors' => array_slice($errors, 0, 100),
                'changes' => $changes === [] ? null : $changes,
            ]);
        });
    }

    /**
     * Harga dari sel Excel. Sel angka dipakai apa adanya (1250000.5 tetap 1250000,5, bukan dibuang titiknya); teks dibaca dengan format
     * Indonesia (1.500.000 atau 1.500.000,50), format Inggris (1,500,000.50), atau koma desimal biasa (1500,5). Bukan angka = null.
     */
    private function parsePrice(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }

        $s = preg_replace('/\s+|Rp/i', '', (string) $raw);

        if ($s === '' || $s === null) {
            return null;
        }

        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
            $s = str_replace(',', '.', str_replace('.', '', $s));
        } elseif (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) {
            $s = str_replace(',', '', $s);
        } elseif (preg_match('/^\d+,\d+$/', $s)) {
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }
}
