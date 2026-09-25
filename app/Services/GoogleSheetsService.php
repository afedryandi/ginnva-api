<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien minimal untuk baca Google Sheets via REST API v4, pakai flow
 * OAuth2 Service Account (JWT Bearer) — sengaja tanpa package google/apiclient,
 * cukup openssl_sign() bawaan PHP + Http facade, biar tidak nambah dependency
 * baru untuk fitur yang cuma butuh READ-ONLY satu spreadsheet.
 */
class GoogleSheetsService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const CACHE_KEY = 'google_pricelist_access_token';

    /**
     * Ambil access token dari cache, atau minta baru ke Google kalau
     * belum ada/sudah kedaluwarsa. Di-cache 50 menit (token asli berlaku
     * 60 menit) supaya tidak re-auth di setiap request.
     */
    private function getAccessToken(): string
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(50), function () {
            $serviceAccount = $this->loadServiceAccount();
            $jwt = $this->buildSignedJwt($serviceAccount);

            $response = Http::asForm()->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Gagal mendapatkan access token Google Sheets: '.$response->status());
            }

            $accessToken = $response->json('access_token');

            if (! is_string($accessToken) || $accessToken === '') {
                throw new RuntimeException('Response token Google Sheets tidak berisi access_token.');
            }

            return $accessToken;
        });
    }

    private function loadServiceAccount(): array
    {
        $path = config('services.google_pricelist.service_account_path');

        if (! is_string($path) || ! file_exists($path)) {
            throw new RuntimeException('File service account Google Pricelist tidak ditemukan di: '.$path);
        }

        $decoded = json_decode(file_get_contents($path), true);

        if (! is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            throw new RuntimeException('File service account Google Pricelist tidak valid.');
        }

        return $decoded;
    }

    private function buildSignedJwt(array $serviceAccount): string
    {
        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header)),
            $this->base64UrlEncode(json_encode($claims)),
        ];

        $signingInput = implode('.', $segments);

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);

        if (! $signed) {
            throw new RuntimeException('Gagal menandatangani JWT service account Google Pricelist.');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Ambil isi range tertentu dari spreadsheet, mis. "Harga!A2:B100".
     * valueRenderOption=UNFORMATTED_VALUE dipakai supaya angka datang
     * sebagai number kalau memungkinkan — tapi kolom SQM di sheet ini
     * tetap perlu diverifikasi/di-parse defensif di PricelistDataService
     * karena cara input data owner di kolom tsb bisa membuat Sheets API
     * tetap mengembalikannya sebagai string.
     */
    public function getValues(string $range): array
    {
        $spreadsheetId = config('services.google_pricelist.spreadsheet_id');
        $accessToken = $this->getAccessToken();

        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/".rawurlencode($range);

        $response = Http::withToken($accessToken)->get($url, [
            'valueRenderOption' => 'UNFORMATTED_VALUE',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gagal membaca Google Sheet range "'.$range.'": HTTP '.$response->status());
        }

        return $response->json('values') ?? [];
    }
}
