<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pengaman: test memakai RefreshDatabase (migrate:fresh = HAPUS SEMUA
     * TABEL). Kalau konfigurasi ter-cache (config:cache) atau env menimpa
     * phpunit.xml, test bisa diam-diam menyasar database aplikasi sungguhan
     * (kejadian 2026-10-06: database dev terhapus). Test DIBATALKAN sebelum
     * menyentuh database kalau nama database bukan database khusus test.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        $isSafe = $connection === 'sqlite'
            ? $database === ':memory:' || str_contains($database, 'testing')
            : str_ends_with($database, '_testing') || str_ends_with($database, 'testing');

        if (! $isSafe) {
            throw new RuntimeException(
                "Test dibatalkan: database \"{$database}\" bukan database test (harus berakhiran \"testing\"). "
                . 'Jalankan "php artisan config:clear" dulu dan pastikan phpunit.xml mengarah ke ginnva_testing.'
            );
        }
    }
}
