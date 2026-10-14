<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Hapus kunci idempotensi yang sudah kedaluwarsa (24 jam)';

    public function handle(): int
    {
        $deleted = DB::table('idempotency_keys')->where('expires_at', '<', now())->delete();

        $this->info("{$deleted} kunci idempotensi kedaluwarsa dihapus.");

        return self::SUCCESS;
    }
}
