<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Payroll;
use App\Models\Store;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Demo/testing manual untuk Penggajian — bikin 1 user dummy ("Demo
 * Payroll") + riwayat Attendance 1 bulan dengan pola telat yang sengaja
 * naik bertahap, supaya jelas kelihatan hari mana yang menghabiskan
 * toleransi dan mulai kena potongan. TIDAK menyentuh data karyawan asli
 * sama sekali — user & attendance-nya semua ditandai dengan email
 * 'demo.payroll@ginnva.test' supaya gampang dibersihkan lagi (--cleanup).
 */
class DemoPayroll extends Command
{
    use \App\Console\Commands\Concerns\DemoDataGuard;

    protected $signature = 'payroll:demo
        {--store_id= : ID toko yang dipakai (default: toko aktif pertama)}
        {--cleanup : Hapus user & data demo ini, bukan generate}';

    protected $description = 'Bikin data dummy (karyawan + absensi 1 bulan) lalu generate Payroll-nya, untuk lihat cara kerja & hasil perhitungan';

    private const DEMO_EMAIL = 'demo.payroll@ginnva.test';

    public function handle(): int
    {
        if ($this->refuseOutsideDemoEnvironment()) {
            return self::FAILURE;
        }

        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        $store = $this->option('store_id')
            ? Store::find($this->option('store_id'))
            : Store::where('is_active', true)->first();

        if (! $store) {
            $this->error('Tidak ada toko aktif ditemukan. Isi --store_id=<id> atau pastikan minimal 1 toko aktif.');
            return self::FAILURE;
        }

        $this->info("Toko dipakai: {$store->name} (ID {$store->id})");

        $rule = \App\Models\AttendanceDeductionRule::effectiveFor(Carbon::now()->startOfMonth());

        if ($rule) {
            $this->info("Aturan potongan: blok telat {$rule->late_block_minutes} menit x Rp" . number_format((float) $rule->late_deduction_per_block, 0, ',', '.') . ', denda Alpha Rp' . number_format((float) $rule->alpha_fine_per_day, 0, ',', '.') . ' per hari');
        } else {
            $this->warn('⚠ Aturan Potongan Absensi belum diisi (menu Karyawan > Aturan Potongan Absensi); generate di bawah akan gagal sampai aturan dibuat.');
        }

        $baseSalary = 5000000;
        $user = User::updateOrCreate(
            ['email' => self::DEMO_EMAIL],
            [
                'name' => 'Demo Payroll (Test)',
                // Cast 'password' => 'hashed' di User model yang meng-hash
                // ini otomatis saat disimpan — jangan Hash::make() manual
                // di sini, nanti ke-hash dua kali.
                'password' => Str::random(32),
                'store_id' => $store->id,
                'base_salary' => $baseSalary,
                'join_date' => now()->subYear(),
            ]
        );

        $month = Carbon::now()->startOfMonth();

        // Pola telat sengaja dirancang: 4 hari pertama masih di bawah
        // toleransi (kumulatif belum lewat), hari ke-5 yang MENGHABISKAN
        // sisa toleransi (jadi ikut kena), hari-hari setelahnya semua kena
        // karena toleransi sudah habis duluan.
        $latePattern = [1 => 5, 3 => 4, 5 => 3, 7 => 6, 9 => 20, 11 => 8, 13 => 10];

        Attendance::where('user_id', $user->id)
            ->whereYear('date', $month->year)
            ->whereMonth('date', $month->month)
            ->delete();

        $this->newLine();
        $this->info("Membuat riwayat absensi bulan {$month->translatedFormat('F Y')}:");
        $this->table(
            ['Hari ke-', 'Tanggal', 'Telat (menit)'],
            collect($latePattern)->map(fn ($minutes, $day) => [
                $day, $month->copy()->addDays($day - 1)->toDateString(), $minutes,
            ])->values()->all()
        );

        foreach ($latePattern as $day => $lateMinutes) {
            $date = $month->copy()->addDays($day - 1);
            Attendance::create([
                'user_id' => $user->id,
                'store_id' => $store->id,
                'date' => $date->toDateString(),
                'entry_type' => 'clock',
                'clock_in_at' => $date->copy()->setTime(9, $lateMinutes),
                'late_minutes' => $lateMinutes,
            ]);
        }

        $this->newLine();
        $this->info('Menggenerate Payroll...');

        try {
            $payroll = Payroll::generateForMonth($user, $month);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('=== HASIL PAYROLL ===');
        $this->table(['Field', 'Nilai'], [
            ['Karyawan', $user->name],
            ['Periode', $payroll->period_month->translatedFormat('F Y')],
            ['Gaji Pokok', 'Rp' . number_format($payroll->base_salary, 0, ',', '.')],
            ['Total Menit Telat', $payroll->total_late_minutes . ' menit'],
            ['Blok Telat Terpotong', ($payroll->late_blocks ?? 0) . ' blok x ' . ($payroll->late_block_minutes ?? '-') . ' menit'],
            ['Potongan per Blok', 'Rp' . number_format((float) $payroll->deduction_per_block, 0, ',', '.')],
            ['Total Potongan', 'Rp' . number_format($payroll->total_deduction, 0, ',', '.')],
            ['Gaji Bersih', 'Rp' . number_format($payroll->net_pay, 0, ',', '.')],
            ['Status', $payroll->status],
        ]);

        $this->newLine();
        $this->info('Bisa dilihat juga di Filament: Karyawan > Penggajian.');
        $this->info('Kalau sudah selesai lihat-lihat, bersihkan datanya dengan: php artisan payroll:demo --cleanup');

        return self::SUCCESS;
    }

    private function cleanup(): int
    {
        $user = User::where('email', self::DEMO_EMAIL)->first();

        if (! $user) {
            $this->info('Tidak ada data demo untuk dibersihkan.');
            return self::SUCCESS;
        }

        Payroll::where('user_id', $user->id)->delete();
        Attendance::where('user_id', $user->id)->delete();
        $user->delete();

        $this->info('Data demo (user, attendance, payroll) sudah dihapus.');
        return self::SUCCESS;
    }
}
