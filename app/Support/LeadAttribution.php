<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Atribusi lead (Marketing/Konten Fase 0, 2026-10-22): menyaring data sumber lead yang dikirim klien (situs web dan aplikasi) lalu
 * mengembalikan kolom yang siap disimpan. Data dari klien TIDAK dipercaya: hanya kunci yang dikenal, dipotong ke panjang kolom,
 * tanpa karakter kendali, perujuk dan halaman asal tanpa query string (query bisa memuat data pribadi).
 *
 * Klien mengirim objek `attribution` {utm_source, utm_medium, utm_campaign, utm_content, click_id, landing_path, referrer}.
 * Kanal ditentukan server: aplikasi mobile (header X-App-Platform) = "app", selain itu = "web_form"; jalur staf menetapkannya sendiri.
 */
class LeadAttribution
{
    /** Kunci kanal => label tampilan. */
    public const CHANNELS = [
        'web_form' => 'Formulir Web',
        'app' => 'Aplikasi',
        'whatsapp' => 'WhatsApp',
        'telepon' => 'Telepon',
        'walk_in' => 'Datang Langsung',
        'instagram_dm' => 'DM Instagram',
        'lainnya' => 'Lainnya',
    ];

    /** Kanal yang boleh dipilih staf saat mencatat lead manual. */
    public const MANUAL_CHANNELS = ['whatsapp', 'telepon', 'walk_in', 'instagram_dm', 'lainnya'];

    private const LIMITS = [
        'utm_source' => 100,
        'utm_medium' => 100,
        'utm_campaign' => 150,
        'utm_content' => 150,
        'click_id' => 191,
        'landing_path' => 255,
        'referrer' => 255,
    ];

    /**
     * @return array<string, ?string> kolom channel, utm_*, click_id, landing_path, referrer
     */
    public static function fromRequest(Request $request, ?string $forcedChannel = null): array
    {
        $input = $request->input('attribution');
        $input = is_array($input) ? $input : [];

        $out = ['channel' => static::channelFor($request, $forcedChannel)];

        foreach (self::LIMITS as $key => $limit) {
            $out[$key] = static::clean($input[$key] ?? null, $limit, $key);
        }

        return $out;
    }

    public static function channelFor(Request $request, ?string $forcedChannel = null): string
    {
        if ($forcedChannel !== null && array_key_exists($forcedChannel, self::CHANNELS)) {
            return $forcedChannel;
        }

        return $request->headers->has('X-App-Platform') ? 'app' : 'web_form';
    }

    private static function clean(mixed $value, int $limit, string $key): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // Buang karakter kendali (termasuk baris baru) dan spasi di ujung.
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        if ($value === '') {
            return null;
        }

        if ($key === 'click_id') {
            // gclid/fbclid hanya berisi huruf, angka, garis bawah, tanda hubung, titik.
            $value = (string) preg_replace('/[^A-Za-z0-9_\-.]/', '', $value);
        }

        if ($key === 'landing_path' || $key === 'referrer') {
            // Tanpa query string/fragmen: bisa memuat data pribadi atau token.
            $value = (string) preg_replace('/[?#].*$/', '', $value);
        }

        $value = mb_substr($value, 0, $limit);

        return $value === '' ? null : $value;
    }
}
