<?php

namespace App\Console\Commands;

use App\Filament\Pages\EmployeeDataCompletenessReport;
use App\Models\AttendanceDeductionRule;
use App\Models\User;
use App\Services\EmployeeDataCompletenessService;
use App\Services\StaffNotifier;
use Illuminate\Console\Command;

/**
 * Pengingat bulanan kelengkapan data karyawan (audit Kelengkapan Data Karyawan, 2026-10-31): tanggal 25 diingatkan karyawan aktif yang butir
 * WAJIB-nya masih kurang (gaji pokok, toko, tanggal bergabung, dst.), supaya payroll akhir bulan tidak terhenti atau salah. Bila belum ada
 * Aturan Potongan Absensi yang berlaku untuk bulan ini, itu ikut disebut (payroll tidak bisa digenerate tanpanya). Tidak ada yang kurang
 * dan aturan sudah ada: tidak ada pesan. Penerima: akses penuh dan staf yang menu Kelengkapan Data Karyawan-nya dicentang eksplisit.
 * Selama pesan sebelumnya belum dibaca, barisnya diperbarui, bukan ditumpuk.
 */
class NotifyIncompleteEmployeeData extends Command
{
    protected $signature = 'hr:notify-incomplete-data';

    protected $description = 'Kirim pengingat bulanan karyawan yang data wajibnya belum lengkap ke akses penuh dan pemegang menu Kelengkapan Data Karyawan';

    public function handle(): int
    {
        $incomplete = app(EmployeeDataCompletenessService::class)->findings()->filter(fn (array $row) => $row['required'] !== [])->values();
        $noRule = AttendanceDeductionRule::effectiveFor(today()->startOfMonth()) === null;

        if ($incomplete->isEmpty() && ! $noRule) {
            $this->info('Data karyawan sudah lengkap, tidak ada yang diingatkan.');

            return self::SUCCESS;
        }

        $recipients = User::where('is_active', true)->with('roles')->get()
            ->filter(fn (User $user) => $user->hasExplicitMenuAccess(EmployeeDataCompletenessReport::class));

        if ($recipients->isNotEmpty()) {
            app(StaffNotifier::class)->toUsers($recipients->pluck('id')->all(), 'Data Karyawan Belum Lengkap', $this->body($incomplete, $noRule), [], 'employee-data-incomplete', 'warning');
        }

        $this->info("{$incomplete->count()} karyawan, {$recipients->count()} penerima diingatkan.");

        return self::SUCCESS;
    }

    private function body($incomplete, bool $noRule): string
    {
        $parts = [];

        if ($incomplete->isNotEmpty()) {
            $names = $incomplete->take(4)->map(fn (array $row) => $row['user']->name)->implode(', ');
            $more = $incomplete->count() > 4 ? ' dan ' . ($incomplete->count() - 4) . ' lainnya' : '';
            $parts[] = "{$incomplete->count()} karyawan masih kurang data wajib untuk payroll: {$names}{$more}.";
        }

        if ($noRule) {
            $parts[] = 'Belum ada Aturan Potongan Absensi yang berlaku bulan ini, payroll tidak bisa digenerate.';
        }

        return implode(' ', $parts) . ' Buka Karyawan > Kelengkapan Data Karyawan.';
    }
}
