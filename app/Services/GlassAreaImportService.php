<?php

namespace App\Services;

use App\Models\Vehicle;
use App\Models\VehicleGlassArea;
use Illuminate\Support\Facades\DB;

/**
 * Impor luas kaca dari Google Sheet "Data Ukuran" ke database (Master Data Fase 2, 2026-10-21). Dua langkah: analyze() hanya membaca dan
 * melaporkan (baris baru, sama, berubah, bermasalah, ganda, tidak ada di katalog kendaraan); import() menyimpan. Tidak ada yang ditimpa
 * tanpa izin: baris yang sudah ada dengan nilai berbeda hanya diperbarui bila $overwrite benar. Sheet tidak pernah ditulis.
 *
 * Kolom sheet (A sampai F): merek, tipe, kaca depan, samping, belakang, sunroof (m²; koma desimal Indonesia diterima).
 */
class GlassAreaImportService
{
    public const RANGE = 'Data Ukuran!A2:F2000';

    public function __construct(private GoogleSheetsService $sheets)
    {
    }

    /** Baca sheet apa adanya (tanpa cache). @return list<array<int, mixed>> */
    public function fetchRows(): array
    {
        return $this->sheets->getValues(self::RANGE);
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     * @return array{entries: list<array<string, mixed>>, problems: list<string>, summary: array<string, int>}
     */
    public function analyze(array $rows): array
    {
        $existing = VehicleGlassArea::all()->keyBy(fn (VehicleGlassArea $a) => $a->brand_key . '|' . $a->model_key);
        $catalog = Vehicle::query()->get(['brand', 'model'])
            ->mapToGroups(fn (Vehicle $v) => [VehicleGlassArea::keyOf((string) $v->brand) . '|' . VehicleGlassArea::keyOf((string) $v->model) => 1])
            ->keys()->flip();

        $entries = [];
        $problems = [];
        $seen = [];
        $summary = ['total_rows' => 0, 'valid' => 0, 'new' => 0, 'unchanged' => 0, 'changed' => 0, 'invalid' => 0, 'duplicates' => 0, 'without_vehicle' => 0];

        foreach ($rows as $index => $row) {
            $line = $index + 2;   // baris sheet (data mulai baris 2)
            $brand = trim((string) ($row[0] ?? ''));
            $model = trim((string) ($row[1] ?? ''));

            if ($brand === '' && $model === '') {
                continue;   // baris kosong
            }

            $summary['total_rows']++;

            if ($brand === '' || $model === '') {
                $summary['invalid']++;
                $problems[] = "Baris {$line}: merek atau tipe kosong.";

                continue;
            }

            $values = [];
            $bad = null;
            foreach (['front_sqm' => 2, 'side_sqm' => 3, 'rear_sqm' => 4, 'sunroof_sqm' => 5] as $field => $column) {
                $parsed = $this->parseValue($row[$column] ?? null);

                if ($parsed === null) {
                    $bad = "Baris {$line} ({$brand} {$model}): nilai kolom " . chr(65 + $column) . ' bukan angka yang valid.';
                    break;
                }

                $values[$field] = $parsed;
            }

            if ($bad === null && array_sum($values) <= 0) {
                $bad = "Baris {$line} ({$brand} {$model}): semua luas kosong atau nol.";
            }

            if ($bad !== null) {
                $summary['invalid']++;
                $problems[] = $bad;

                continue;
            }

            $key = VehicleGlassArea::keyOf($brand) . '|' . VehicleGlassArea::keyOf($model);

            if (isset($seen[$key])) {
                $summary['duplicates']++;
                $same = $seen[$key] == $values;
                $problems[] = $same
                    ? "Baris {$line} ({$brand} {$model}): ganda dengan nilai sama, diabaikan."
                    : "Baris {$line} ({$brand} {$model}): ganda dengan nilai BERBEDA dari baris sebelumnya; yang pertama dipakai.";

                continue;
            }
            $seen[$key] = $values;

            $current = $existing->get($key);
            $status = match (true) {
                $current === null => 'new',
                $this->sameValues($current, $values) => 'unchanged',
                default => 'changed',
            };

            $hasVehicle = $catalog->has($key);
            if (! $hasVehicle) {
                $summary['without_vehicle']++;
            }

            $summary['valid']++;
            $summary[$status]++;
            $entries[] = ['brand' => $brand, 'model' => $model, 'values' => $values, 'status' => $status, 'has_vehicle' => $hasVehicle, 'line' => $line];
        }

        return ['entries' => $entries, 'problems' => $problems, 'summary' => $summary];
    }

    /**
     * Simpan hasil analyze(). Baris baru selalu dibuat; baris berubah hanya bila $overwrite; baris sama dilewati.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{created: int, updated: int, skipped: int}
     */
    public function import(array $entries, bool $overwrite): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($entries, $overwrite, &$result) {
            foreach ($entries as $entry) {
                if ($entry['status'] === 'unchanged') {
                    $result['skipped']++;

                    continue;
                }

                if ($entry['status'] === 'changed' && ! $overwrite) {
                    $result['skipped']++;

                    continue;
                }

                $payload = $entry['values'] + ['brand' => $entry['brand'], 'model' => $entry['model'], 'source' => 'import', 'imported_at' => now()];

                if ($entry['status'] === 'new') {
                    VehicleGlassArea::create($payload);
                    $result['created']++;
                } else {
                    VehicleGlassArea::where('brand_key', VehicleGlassArea::keyOf($entry['brand']))
                        ->where('model_key', VehicleGlassArea::keyOf($entry['model']))
                        ->firstOrFail()
                        ->update($payload);
                    $result['updated']++;
                }
            }
        });

        return $result;
    }

    /** Angka dari sel sheet: kosong = 0, koma desimal diterima, negatif atau teks = null (tidak valid). */
    private function parseValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return $value < 0 ? null : round((float) $value, 3);
        }

        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return 0.0;
        }

        $text = str_replace(',', '.', $text);

        if (! is_numeric($text) || (float) $text < 0) {
            return null;
        }

        return round((float) $text, 3);
    }

    private function sameValues(VehicleGlassArea $area, array $values): bool
    {
        foreach ($values as $field => $value) {
            if (abs((float) $area->{$field} - $value) > 0.0005) {
                return false;
            }
        }

        return true;
    }
}
