<?php

namespace App\Console\Commands;

use App\Models\Payroll;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Perintah satu kali (audit Riwayat Kontrak, 2026-10-31): karyawan kontrak yang dinonaktifkan otomatis sebelum perbaikan tidak punya Hari
 * Kerja Terakhir, sehingga Generate Payroll melewatkan gaji bulan terakhirnya. Perintah ini mengisinya dengan tanggal akhir kontrak.
 * Bawaannya hanya menampilkan calon (dry-run); tulis dengan --apply setelah daftarnya diperiksa. Karyawan yang sebenarnya berhenti SEBELUM
 * kontrak habis harus diisi manual dengan tanggal sebenarnya, jadi periksa daftarnya dulu.
 */
class BackfillContractLastWorkDate extends Command
{
    protected $signature = 'contracts:backfill-last-work-date {--apply : Tulis perubahan (tanpa ini hanya menampilkan calon)}';

    protected $description = 'Isi Hari Kerja Terakhir karyawan kontrak yang dinonaktifkan otomatis dengan tanggal akhir kontraknya';

    public function handle(): int
    {
        // Batas 12 bulan sama dengan pilihan bulan di Generate Payroll; yang lebih lama tidak mungkin digenerate lagi dari panel.
        $candidates = User::query()
            ->where('is_active', false)
            ->whereNull('last_work_date')
            ->whereNotNull('contract_end_date')
            ->whereDate('contract_end_date', '<', today())
            ->whereDate('contract_end_date', '>=', today()->subMonths(12)->startOfMonth())
            ->whereHas('employeeType', fn ($q) => $q->where('has_end_date', true))
            ->orderBy('contract_end_date')
            ->get()
            ->reject(fn (User $user) => Payroll::where('user_id', $user->id)
                ->whereDate('period_month', $user->contract_end_date->copy()->startOfMonth()->toDateString())->exists());

        if ($candidates->isEmpty()) {
            $this->info('Tidak ada karyawan yang perlu diisi Hari Kerja Terakhirnya.');

            return self::SUCCESS;
        }

        $this->table(['Karyawan', 'Kontrak berakhir'], $candidates->map(fn (User $u) => [$u->name, $u->contract_end_date->format('d M Y')])->all());

        if (! $this->option('apply')) {
            $this->warn("{$candidates->count()} calon. Jalankan lagi dengan --apply setelah daftar diperiksa.");

            return self::SUCCESS;
        }

        foreach ($candidates as $user) {
            $user->update(['last_work_date' => $user->contract_end_date->toDateString()]);
        }

        $this->info("{$candidates->count()} karyawan diisi Hari Kerja Terakhirnya.");

        return self::SUCCESS;
    }
}
