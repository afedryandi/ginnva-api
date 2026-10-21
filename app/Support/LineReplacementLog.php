<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Catatan Histori Aktivitas untuk daftar baris yang diganti sekaligus (Histori Aktivitas Fase 3, 2026-10-21): rincian penjualan
 * booking, pembayaran pelunasan, dan item invoice. Layanan-layanan itu menghapus semua baris lalu membuatnya ulang lewat query
 * massal, yang tidak memicu pencatatan otomatis model, dan pencatatan per baris hanya akan menghasilkan deretan "dibuat" tanpa
 * penghapusan. Maka dicatat SATU entri per penggantian, pada rekaman induknya, berisi baris sebelum dan sesudah.
 *
 * Tidak mencatat apa pun bila isi barisnya sama persis (layanan ini dipanggil tiap simpan, walau tidak ada yang berubah).
 */
class LineReplacementLog
{
    /**
     * @param  list<string>  $oldLines  teks tiap baris sebelum penggantian
     * @param  list<string>  $newLines  teks tiap baris sesudah penggantian
     */
    public static function record(Model $subject, string $logName, string $noun, array $oldLines, array $newLines, float $oldTotal, float $newTotal): void
    {
        if ($oldLines === $newLines) {
            return;
        }

        try {
            $verb = match (true) {
                $oldLines === [] => 'diisi',
                $newLines === [] => 'dikosongkan',
                default => 'diubah',
            };

            $description = "{$noun} {$verb}: " . count($newLines) . ' baris, ' . self::money($newTotal)
                . ($oldLines === [] ? '' : ' (sebelumnya ' . count($oldLines) . ' baris, ' . self::money($oldTotal) . ')');

            activity($logName)
                ->event('updated')
                ->performedOn($subject)
                ->withProperties(['old' => self::rows($oldLines, $oldTotal), 'attributes' => self::rows($newLines, $newTotal)])
                ->log($description);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function money(float|int|string $amount): string
    {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    }

    /** Angka tanpa nol desimal yang tidak perlu: 1,00 menjadi 1; 2,50 menjadi 2,5. */
    public static function number(float|int|string $value): string
    {
        $text = number_format((float) $value, 2, ',', '.');

        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }

    /** @return array<string, string> */
    private static function rows(array $lines, float $total): array
    {
        $rows = [];

        foreach ($lines as $index => $line) {
            $rows['Baris ' . ($index + 1)] = $line;
        }

        if ($lines !== []) {
            $rows['Total'] = self::money($total);
        }

        return $rows;
    }
}
