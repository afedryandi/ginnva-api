<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Business Cloud API (Meta) — dipakai untuk reminder servis
 * berkala (lihat Console\Commands\SendServiceReminders). Pesan proaktif
 * (bukan balasan dalam window 24 jam) WAJIB pakai template yang sudah
 * disetujui Meta, makanya method di sini kirim template, bukan teks bebas.
 *
 * Kalau kredensial (access_token/phone_number_id) belum diisi di .env,
 * method ini diam-diam skip (cuma log) — supaya reminder Push & Email
 * tetap bisa jalan duluan tanpa menunggu approval WhatsApp selesai.
 */
class WhatsAppService
{
    public function isConfigured(): bool
    {
        return filled(config('services.whatsapp.access_token'))
            && filled(config('services.whatsapp.phone_number_id'));
    }

    /**
     * Nomor customer di database kebanyakan disimpan format lokal (08xx),
     * sedangkan WhatsApp Cloud API butuh format internasional (628xx) —
     * tanpa konversi ini, kirim ke nomor 08xx diam-diam gagal/terkirim ke
     * nomor yang salah karena Meta tidak mengenali "0" sebagai kode negara.
     */
    public static function normalizePhoneNumber(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '0')) {
            return '62' . substr($digits, 1);
        }

        if (str_starts_with($digits, '62')) {
            return $digits;
        }

        // Nomor tidak dikenali formatnya (bukan 0xx atau 62xx) — kembalikan
        // apa adanya (sudah di-strip non-digit) supaya Meta yang menolak
        // dengan pesan error yang jelas, bukan disamarkan jadi salah kirim.
        return $digits;
    }

    /**
     * Kirim 1 pesan template ke nomor tujuan.
     *
     * @param string $phoneNumber Nomor tujuan, format lokal (08xx) atau
     *                            internasional (62xx/+62xx) — dinormalisasi
     *                            otomatis sebelum dikirim.
     * @param array<int, string> $bodyParams Nilai untuk placeholder
     *                            {{1}}, {{2}}, dst di body template.
     */
    public function sendTemplate(string $phoneNumber, array $bodyParams = []): bool
    {
        if (! $this->isConfigured()) {
            Log::info('[WhatsApp] Skip kirim — access_token/phone_number_id belum dikonfigurasi.', [
                'to' => $phoneNumber,
            ]);

            return false;
        }

        $normalizedPhone = self::normalizePhoneNumber($phoneNumber);

        if ($normalizedPhone === '') {
            Log::warning('[WhatsApp] Skip kirim — nomor tujuan kosong setelah dinormalisasi.', ['to' => $phoneNumber]);

            return false;
        }

        $phoneNumberId = config('services.whatsapp.phone_number_id');

        try {
            $response = Http::withToken(config('services.whatsapp.access_token'))
                ->post("https://graph.facebook.com/v20.0/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => $normalizedPhone,
                    'type'              => 'template',
                    'template'          => [
                        'name'     => config('services.whatsapp.template_name'),
                        'language' => ['code' => config('services.whatsapp.template_lang')],
                        'components' => empty($bodyParams) ? [] : [[
                            'type'       => 'body',
                            'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => $v], $bodyParams),
                        ]],
                    ],
                ]);

            if (! $response->successful()) {
                Log::error('[WhatsApp] Kirim gagal: ' . $response->body(), ['to' => $phoneNumber]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[WhatsApp] Exception: ' . $e->getMessage(), ['to' => $phoneNumber]);

            return false;
        }
    }
}
