<?php

namespace App\Services;

use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Import katalog kendaraan dari Excel/CSV. Format kolom sama dengan Export Kendaraan (Merek, Tipe/Model, Varian, Ukuran, Tipe Bodi,
 * Tahun Dari, Tahun Sampai, Catatan, Aktif), jadi alurnya: export, koreksi, import. Dua langkah seperti import Luas Kaca: analyze() hanya
 * membaca dan melaporkan; import() menyimpan. Baris yang sudah ada hanya diperbarui bila $overwrite benar; sisanya tidak diubah.
 * Kunci pencocokan: merek + tipe + varian (huruf besar/kecil dan spasi tepi tidak membedakan).
 */
class VehicleImportService
{
    public const MAX_ROWS = 3000;

    /** @return list<array<int, mixed>> baris data (tanpa header) */
    public function readRows(string $absolutePath): array
    {
        $sheets = Excel::toArray(null, $absolutePath);
        $rows = $sheets[0] ?? [];
        array_shift($rows);

        return array_slice($rows, 0, self::MAX_ROWS);
    }

    private static function key(?string $brand, ?string $model, ?string $variant): string
    {
        $n = fn (?string $s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $s)));

        return $n($brand) . '|' . $n($model) . '|' . $n($variant);
    }

    /** Kunci tipe bodi dari teks (kunci atau label); null bila kosong; false bila tidak dikenal. */
    private static function bodyKey(?string $text): string|false|null
    {
        $text = mb_strtolower(trim((string) $text));

        if ($text === '') {
            return null;
        }

        foreach (Vehicle::BODY_TYPES as $key => $label) {
            if ($text === $key || $text === mb_strtolower($label)) {
                return $key;
            }
        }

        return false;
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     * @return array{entries: list<array<string, mixed>>, problems: list<string>, summary: array<string, int>}
     */
    public function analyze(array $rows): array
    {
        $existing = Vehicle::all()->keyBy(fn (Vehicle $v) => self::key($v->brand, $v->model, $v->variant));
        $entries = [];
        $problems = [];
        $seen = [];
        $summary = ['total_rows' => 0, 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'invalid' => 0, 'duplicates' => 0];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $brand = trim((string) ($row[0] ?? ''));
            $model = trim((string) ($row[1] ?? ''));
            $variant = trim((string) ($row[2] ?? ''));

            if ($brand === '' && $model === '' && $variant === '') {
                continue;
            }

            $summary['total_rows']++;

            if ($brand === '') {
                $summary['invalid']++;
                $problems[] = "Baris {$line}: merek kosong.";

                continue;
            }

            if ($model === '' && $variant !== '') {
                $summary['invalid']++;
                $problems[] = "Baris {$line}: varian diisi tapi tipe/model kosong.";

                continue;
            }

            $size = strtoupper(trim((string) ($row[3] ?? '')));
            if (! in_array($size, ['S', 'M', 'L', 'XL', 'XXL'], true)) {
                $summary['invalid']++;
                $problems[] = "Baris {$line}: ukuran \"" . ($row[3] ?? '') . '" tidak dikenal (pakai S, M, L, XL, atau XXL).';

                continue;
            }

            $body = self::bodyKey($row[4] ?? null);
            if ($body === false) {
                $summary['invalid']++;
                $problems[] = "Baris {$line}: tipe bodi \"" . ($row[4] ?? '') . '" tidak dikenal.';

                continue;
            }

            $yearFrom = filled($row[5] ?? null) ? (int) $row[5] : null;
            $yearTo = filled($row[6] ?? null) ? (int) $row[6] : null;
            foreach ([$yearFrom, $yearTo] as $year) {
                if ($year !== null && ($year < 1980 || $year > 2100)) {
                    $summary['invalid']++;
                    $problems[] = "Baris {$line}: tahun {$year} di luar 1980–2100.";

                    continue 2;
                }
            }
            if ($yearFrom !== null && $yearTo !== null && $yearTo < $yearFrom) {
                $summary['invalid']++;
                $problems[] = "Baris {$line}: tahun akhir lebih kecil dari tahun awal.";

                continue;
            }

            $key = self::key($brand, $model, $variant);
            if (isset($seen[$key])) {
                $summary['duplicates']++;
                $problems[] = "Baris {$line}: sama dengan baris {$seen[$key]} (merek+tipe+varian), dilewati.";

                continue;
            }
            $seen[$key] = $line;

            $active = ! in_array(mb_strtolower(trim((string) ($row[8] ?? 'ya'))), ['tidak', 'nonaktif', '0', 'no', 'false'], true);

            $data = [
                'brand' => $brand,
                'model' => $model !== '' ? $model : null,
                'variant' => $variant !== '' ? $variant : null,
                'size_category' => $size,
                'body_type' => $body,
                'year_from' => $yearFrom,
                'year_to' => $yearTo,
                'notes' => filled($row[7] ?? null) ? mb_substr(trim((string) $row[7]), 0, 255) : null,
                'is_active' => $active,
            ];

            $current = $existing->get($key);
            if (! $current) {
                $status = 'new';
            } else {
                $dirty = collect($data)->except(['brand', 'model', 'variant'])->contains(fn ($value, $field) => $current->{$field} != $value);
                $status = $dirty ? 'changed' : 'unchanged';
            }
            $summary[$status]++;

            $entries[] = ['line' => $line, 'status' => $status, 'id' => $current?->id, 'data' => $data];
        }

        return compact('entries', 'problems', 'summary');
    }

    /**
     * @param  list<array<string, mixed>>  $entries  hasil analyze()['entries']
     * @return array{created: int, updated: int, skipped: int}
     */
    public function import(array $entries, bool $overwrite): array
    {
        $created = $updated = $skipped = 0;

        DB::transaction(function () use ($entries, $overwrite, &$created, &$updated, &$skipped) {
            foreach ($entries as $entry) {
                if ($entry['status'] === 'new') {
                    Vehicle::create($entry['data']);
                    $created++;
                } elseif ($entry['status'] === 'changed' && $overwrite) {
                    Vehicle::whereKey($entry['id'])->first()?->update($entry['data']);
                    $updated++;
                } else {
                    $skipped++;
                }
            }
        });

        return compact('created', 'updated', 'skipped');
    }
}
