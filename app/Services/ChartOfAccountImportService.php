<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

/**
 * Impor Bagan Akun dari Excel/CSV (audit Bagan Akun 2026-09-28). Aturan
 * SENGAJA ketat karena struktur akun mempengaruhi seluruh laporan:
 * - Hanya MEMBUAT akun baru. Kode yang sudah ada dilewati dan TIDAK
 *   pernah ditimpa (sama filosofi dengan seeder).
 * - Semua-atau-tidak-sama-sekali: kalau ada 1 baris error, tidak ada
 *   akun yang dibuat, dan semua error dilaporkan sekaligus.
 * - Validasi sama dengan form: kode 4-6 digit, digit pertama cocok dengan
 *   klasifikasi, induk harus akun header (tidak postable) berklasifikasi sama.
 */
class ChartOfAccountImportService
{
    public const TYPES = ['aset', 'kewajiban', 'modal', 'pendapatan', 'beban_pokok', 'beban_operasional', 'pendapatan_lain', 'beban_lain', 'pajak'];

    public const CASH_FLOW = ['operasional', 'investasi', 'pendanaan'];

    /**
     * @param  array<int, array<string, mixed>>  $rows  baris dengan kunci: kode, nama, klasifikasi, kode_induk, postable, akun_kas, akun_kontra, kategori_arus_kas
     * @return array{created: int, skipped: int, errors: string[]}
     */
    public function import(array $rows): array
    {
        $errors = [];
        $parsed = [];
        $seen = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2; // baris 1 = header
            $code = trim((string) ($row['kode'] ?? ''));

            if ($code === '' && trim((string) ($row['nama'] ?? '')) === '') {
                continue; // baris kosong
            }

            $name = trim((string) ($row['nama'] ?? ''));
            $type = strtolower(trim((string) ($row['klasifikasi'] ?? '')));
            $parentCode = trim((string) ($row['kode_induk'] ?? ''));
            $flow = strtolower(trim((string) ($row['kategori_arus_kas'] ?? '')));

            if (! preg_match('/^[0-9]{4,6}$/', $code)) {
                $errors[] = "Baris {$line}: kode \"{$code}\" harus 4-6 digit angka.";
                continue;
            }
            if (isset($seen[$code])) {
                $errors[] = "Baris {$line}: kode {$code} muncul lebih dari sekali di file.";
                continue;
            }
            $seen[$code] = true;

            if ($name === '' || mb_strlen($name) > 255) {
                $errors[] = "Baris {$line} ({$code}): nama wajib diisi, maksimal 255 karakter.";
            }
            if (! in_array($type, self::TYPES, true)) {
                $errors[] = "Baris {$line} ({$code}): klasifikasi \"{$type}\" tidak dikenal.";
            } elseif (! in_array($type, ChartOfAccount::CODE_PREFIX_TYPES[$code[0]] ?? [], true)) {
                $errors[] = "Baris {$line} ({$code}): digit pertama kode tidak cocok dengan klasifikasi {$type}.";
            }
            if ($flow !== '' && ! in_array($flow, self::CASH_FLOW, true)) {
                $errors[] = "Baris {$line} ({$code}): kategori arus kas \"{$flow}\" harus operasional/investasi/pendanaan.";
            }

            $parsed[$code] = [
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'parent_code' => $parentCode,
                'is_postable' => $this->bool($row['postable'] ?? 'ya', true),
                'is_cash' => $this->bool($row['akun_kas'] ?? 'tidak', false),
                'is_contra' => $this->bool($row['akun_kontra'] ?? 'tidak', false),
                'cash_flow_category' => $flow !== '' ? $flow : null,
                'line' => $line,
            ];
        }

        $existing = ChartOfAccount::pluck('id', 'code');
        $existingModels = ChartOfAccount::whereIn('code', array_keys($parsed))->get()->keyBy('code');
        $skipped = $existing->only(array_keys($parsed))->count();

        // Validasi induk: harus ada (di DB atau file), header, klasifikasi sama.
        foreach ($parsed as $code => $data) {
            if ($data['parent_code'] === '') {
                continue;
            }
            $parentType = null;
            $parentPostable = null;
            if (isset($parsed[$data['parent_code']])) {
                $parentType = $parsed[$data['parent_code']]['type'];
                $parentPostable = $parsed[$data['parent_code']]['is_postable'];
            } elseif ($p = ChartOfAccount::where('code', $data['parent_code'])->first()) {
                $parentType = $p->type;
                $parentPostable = $p->is_postable;
            }

            if ($parentType === null) {
                $errors[] = "Baris {$data['line']} ({$code}): kode induk {$data['parent_code']} tidak ditemukan.";
            } elseif ($parentPostable) {
                $errors[] = "Baris {$data['line']} ({$code}): akun induk {$data['parent_code']} harus akun header (tidak postable).";
            } elseif ($parentType !== $data['type']) {
                $errors[] = "Baris {$data['line']} ({$code}): klasifikasi induk {$data['parent_code']} berbeda.";
            }
        }

        if ($errors) {
            return ['created' => 0, 'skipped' => $skipped, 'errors' => $errors];
        }

        $created = 0;
        DB::transaction(function () use ($parsed, $existingModels, &$created) {
            ksort($parsed); // induk (kode lebih kecil) lebih dulu
            $ids = ChartOfAccount::pluck('id', 'code')->all();

            foreach ($parsed as $code => $data) {
                if ($existingModels->has($code)) {
                    continue;
                }

                $account = ChartOfAccount::create([
                    'code' => $code,
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'normal_balance' => ChartOfAccount::normalBalanceFor($data['type']),
                    'parent_id' => $data['parent_code'] !== '' ? ($ids[$data['parent_code']] ?? null) : null,
                    'is_postable' => $data['is_postable'],
                    'is_active' => true,
                    'is_cash' => $data['is_cash'],
                    'is_contra' => $data['is_contra'],
                    'cash_flow_category' => $data['cash_flow_category'],
                ]);

                $ids[$code] = $account->id;
                $created++;
            }
        });

        return ['created' => $created, 'skipped' => $skipped, 'errors' => []];
    }

    private function bool(mixed $value, bool $default): bool
    {
        $v = strtolower(trim((string) $value));
        if ($v === '') {
            return $default;
        }

        return in_array($v, ['ya', 'y', 'yes', 'true', '1'], true);
    }
}
