<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Lapisan di atas GoogleSheetsService yang menerjemahkan raw rows jadi
 * data terstruktur untuk kalkulator Price List Kaca Film. Semua method
 * di-cache 5 menit — owner mengedit sheet sesekali saja, jadi staleness
 * 5 menit lebih murah daripada baca Sheets API di setiap request.
 */
class PricelistDataService
{
    private const CACHE_TTL_MINUTES = 5;

    public function __construct(private GoogleSheetsService $sheets)
    {
    }

    /**
     * Baca sekali seluruh range "Data Ukuran" dan cache — dipakai bareng
     * oleh getBrands()/getModels()/getCarSqm() supaya tidak 3x baca
     * Sheets API untuk satu kali load halaman kalkulator.
     */
    private function getRawCarSizeRows(): array
    {
        return Cache::remember('pricelist_data_ukuran_raw', now()->addMinutes(self::CACHE_TTL_MINUTES), function () {
            return $this->sheets->getValues('Data Ukuran!A2:F2000');
        });
    }

    public function getAllowedEmails(): array
    {
        return Cache::remember('pricelist_akses_emails', now()->addMinutes(self::CACHE_TTL_MINUTES), function () {
            $rows = $this->sheets->getValues('Akses!A2:A1000');

            $emails = [];
            foreach ($rows as $row) {
                $email = trim((string) ($row[0] ?? ''));
                if ($email !== '') {
                    $emails[] = strtolower($email);
                }
            }

            return array_values(array_unique($emails));
        });
    }

    public function isEmailAllowed(string $email): bool
    {
        return in_array(strtolower(trim($email)), $this->getAllowedEmails(), true);
    }

    public function getBrands(): array
    {
        $brands = [];

        foreach ($this->getRawCarSizeRows() as $row) {
            $brand = trim((string) ($row[0] ?? ''));
            if ($brand !== '') {
                $brands[$brand] = true;
            }
        }

        $result = array_keys($brands);
        sort($result);

        return $result;
    }

    public function getModels(string $brand): array
    {
        $brand = trim($brand);
        $models = [];

        foreach ($this->getRawCarSizeRows() as $row) {
            $rowBrand = trim((string) ($row[0] ?? ''));
            $rowModel = trim((string) ($row[1] ?? ''));

            if ($rowBrand === $brand && $rowModel !== '') {
                $models[$rowModel] = true;
            }
        }

        $result = array_keys($models);
        sort($result);

        return $result;
    }

    public function getCarSqm(string $brand, string $tipe): ?array
    {
        $brand = trim($brand);
        $tipe = trim($tipe);

        foreach ($this->getRawCarSizeRows() as $row) {
            $rowBrand = trim((string) ($row[0] ?? ''));
            $rowModel = trim((string) ($row[1] ?? ''));

            if ($rowBrand === $brand && $rowModel === $tipe) {
                return [
                    'sqmDepan' => $this->parseSqmValue($row[2] ?? null),
                    'sqmSamping' => $this->parseSqmValue($row[3] ?? null),
                    'sqmBelakang' => $this->parseSqmValue($row[4] ?? null),
                    'sqmSunroof' => $this->parseSqmValue($row[5] ?? null),
                ];
            }
        }

        return null;
    }

    /**
     * Parsing defensif: kalau Sheets API sudah mengembalikan number murni
     * (hasil verifikasi UNFORMATTED_VALUE), langsung cast float. Kalau
     * ternyata masih string locale Indonesia (koma sebagai desimal,
     * mis. "1,071"), ganti koma jadi titik dulu sebelum cast.
     */
    private function parseSqmValue(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return 0.0;
        }

        return (float) str_replace(',', '.', $value);
    }

    public function getHargaMap(): array
    {
        return Cache::remember('pricelist_harga_map', now()->addMinutes(self::CACHE_TTL_MINUTES), function () {
            $rows = $this->sheets->getValues('Harga!A2:B100');

            $map = [];
            foreach ($rows as $row) {
                $produk = trim((string) ($row[0] ?? ''));
                $harga = $row[1] ?? null;

                if ($produk === '' || $harga === null || $harga === '') {
                    continue;
                }

                $map[$produk] = is_numeric($harga)
                    ? (float) $harga
                    : (float) str_replace(',', '.', (string) $harga);
            }

            return $map;
        });
    }
}
