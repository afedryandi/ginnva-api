<?php

namespace App\Console\Commands\Concerns;

/**
 * Pengaman perintah data demo: hanya boleh jalan di server dev/test. APP_ENV saja TIDAK cukup (server production
 * pernah ber-APP_ENV=local), jadi nama database ikut diperiksa — harus mengandung "dev", "demo", atau "test".
 */
trait DemoDataGuard
{
    protected function refuseOutsideDemoEnvironment(): bool
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $looksLikeDemo = (bool) preg_match('/(dev|demo|test)/i', $database);

        if (app()->environment('production') || ! $looksLikeDemo) {
            $this->error("Perintah data demo hanya boleh dijalankan di server dev/test. Database saat ini \"{$database}\" tidak dikenali sebagai database dev/demo, jadi dibatalkan.");

            return true;
        }

        return false;
    }
}
