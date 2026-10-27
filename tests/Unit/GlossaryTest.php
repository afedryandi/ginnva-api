<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Daftar istilah baku (docs/AUDIT_BAHASA.md): satu konsep, satu sebutan. Tes ini memeriksa teks tampilan di panel dan API supaya sebutan lama
 * tidak kembali. Yang diperiksa hanya string berisi kata (bukan kunci kode seperti 'customer_id'), dan komentar tidak ikut.
 */
class GlossaryTest extends TestCase
{
    /** sebutan lama => sebutan baku */
    private const FORBIDDEN = [
        'Customer' => 'Pelanggan', 'customer' => 'pelanggan',
        'Teknisi' => 'Installer', 'teknisi' => 'installer',
        'Ekspor' => 'Export', 'ekspor' => 'export',
        'Impor' => 'Import', 'impor' => 'import',
        'Unduh' => 'Download', 'unduh' => 'download',
        'Templat' => 'Template', 'templat' => 'template',
        'Daftar Periksa' => 'Checklist', 'daftar periksa' => 'checklist',
        'Staf' => 'Staff', 'staf' => 'staff',
        'Ulasan' => 'Review', 'ulasan' => 'review',
        'Cabang' => 'Toko', 'cabang' => 'toko',
        'Histori' => 'Riwayat', 'histori' => 'riwayat',
        'Kupon' => 'Voucher', 'kupon' => 'voucher',
        'Pengguna' => 'User', 'Kata sandi' => 'Password', 'kata sandi' => 'password',
        'Tidak aktif' => 'Nonaktif', 'tidak aktif' => 'nonaktif',
    ];

    /** Pengecualian yang disengaja: berkas => daftar potongan teks yang boleh memuat sebutan lama (data, bukan tampilan). */
    private const ALLOWED = [
        'app/Console/' => null, // perintah CLI dan data demo (penanda pembersihan), bukan tampilan
        'app/Support/ActivityPresenter.php' => ['Customer'], // kunci pemetaan nama model
    ];

    public function test_display_strings_use_the_agreed_terms(): void
    {
        $root = dirname(__DIR__, 2);
        $violations = [];

        foreach (['app/Filament', 'app/Http', 'app/Models', 'app/Services', 'app/Notifications', 'app/Exports', 'app/Support', 'app/Mail', 'app/Providers'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

                foreach (self::ALLOWED as $prefix => $words) {
                    if (str_starts_with($relative, $prefix) && $words === null) {
                        continue 2;
                    }
                }

                foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                    if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                        continue;
                    }

                    $text = $token[1];

                    if (! preg_match('/\s/', $text) && ! preg_match("/^['\"][A-Z]/", $text)) {
                        continue; // kata tunggal huruf kecil = kunci kode
                    }

                    if (preg_match('/(SELECT|MIN|COALESCE|JOIN)/', $text)) {
                        continue; // potongan SQL, bukan tampilan
                    }

                    foreach (self::FORBIDDEN as $old => $new) {
                        if (preg_match('/(?<![\w.$\\\\\/\-@>])' . preg_quote($old, '/') . '(?![\w.\\\\\/@])/', $text)) {
                            foreach (self::ALLOWED[$relative] ?? [] as $allowed) {
                                if ($allowed === $old) {
                                    continue 2;
                                }
                            }

                            $violations[] = "{$relative}:{$token[2]} memuat \"{$old}\" (pakai \"{$new}\"): " . trim(substr($text, 0, 90));
                        }
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Sebutan lama ditemukan:\n" . implode("\n", array_slice($violations, 0, 30)));
    }
}
