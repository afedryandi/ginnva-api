<?php

namespace App\Console\Commands;

use App\Models\ContractExtension;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Perintah satu kali (audit Riwayat Kontrak, 2026-10-31): karyawan kontrak yang tanggal akhir kontraknya diisi langsung di form User sebelum
 * ada pencatatan otomatis tidak punya baris di Riwayat Kontrak. Perintah ini membuatkan baris "kontrak pertama" dari tanggal yang sekarang
 * tercatat. Tidak mengubah data karyawan. Bawaannya hanya menampilkan calon; tulis dengan --apply.
 */
class BackfillContractHistory extends Command
{
    protected $signature = 'contracts:backfill-history {--apply : Tulis perubahan (tanpa ini hanya menampilkan calon)}';

    protected $description = 'Buat baris Riwayat Kontrak (kontrak pertama) untuk karyawan kontrak aktif yang riwayatnya masih kosong';

    public function handle(): int
    {
        $candidates = User::query()
            ->where('is_active', true)
            ->whereNotNull('contract_end_date')
            ->whereHas('employeeType', fn ($q) => $q->where('has_end_date', true))
            ->whereDoesntHave('contractExtensions')
            ->orderBy('name')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Semua karyawan kontrak sudah punya riwayat.');

            return self::SUCCESS;
        }

        $this->table(['Karyawan', 'Kontrak berakhir'], $candidates->map(fn (User $u) => [$u->name, $u->contract_end_date->format('d M Y')])->all());

        if (! $this->option('apply')) {
            $this->warn("{$candidates->count()} calon. Jalankan lagi dengan --apply untuk membuat riwayatnya.");

            return self::SUCCESS;
        }

        $created = $candidates->filter(fn (User $user) => ContractExtension::recordFirstContractIfMissing($user) !== null)->count();

        $this->info("{$created} baris riwayat dibuat.");

        return self::SUCCESS;
    }
}
