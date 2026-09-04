<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\ContractExtension;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\Store;
use App\Models\User;
use App\Models\WarningLetter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Demo/testing manual untuk nav group "Karyawan" (HR): Absensi Karyawan,
 * Izin & Cuti, Penggajian, Surat Peringatan, Perpanjang Kontrak.
 *
 * SENGAJA TIDAK menempel data dummy ke akun staff ASLI — data HR beda
 * karakter dari Marketing/Booking (bukan sekadar UI demo, tapi riwayat
 * personal karyawan: absensi, gaji, SP bisa kebaca staff yang bersangkutan
 * lewat mobile app). Sebagai gantinya, command ini bikin 2 akun karyawan
 * DEMO baru (role 'store_manager', supaya store_id/base_salary relevan
 * & konsisten dengan alur Payroll::generateForMonth()) yang jelas
 * ditandai "Demo -" dan email @demo.ginnva.test, dilampiri riwayat
 * HR dummy, lalu bisa dihapus total lewat --cleanup — tidak pernah
 * menyentuh baris User asli sama sekali.
 *
 * Tombol "Setujui" LeaveRequest di Filament (lihat LeaveRequestResource::
 * table() action 'approve') memanggil PushNotificationService::
 * sendToUsers() — push notification SUNGGUHAN ke HP user_id terkait.
 * Command ini TIDAK memanggil action itu; approve/reject disimulasikan
 * dengan ->update() langsung ke kolom status/reviewed_by/reviewed_at,
 * supaya tidak ada push notification yang terkirim (toh user_id-nya
 * akun demo, tapi tetap dihindari sebagai praktik aman).
 *
 * Payroll TIDAK diisi manual — dihitung lewat Payroll::generateForMonth()
 * (logic asli, baca dari Attendance yang sudah dibuat di command ini)
 * supaya potongan telat/alpha & net_pay konsisten dengan alur produksi,
 * bukan angka karangan.
 *
 * Tidak ada Observer terdaftar untuk Attendance/LeaveRequest/Payroll/
 * WarningLetter/ContractExtension/User (lihat AppServiceProvider::boot())
 * — semua dibuat dengan create()/service method asli biasa.
 */
class DemoKaryawanGroup extends Command
{
    protected $signature = 'karyawan-group:demo
        {--cleanup : Hapus semua data demo grup ini, bukan generate}';

    protected $description = 'Bikin data dummy untuk nav group Karyawan (Absensi, Izin & Cuti, Penggajian, SP, Perpanjang Kontrak)';

    private const MARK_PREFIX = 'Demo - ';
    private const DEMO_EMAIL_SUFFIX = '@demo.ginnva.test';

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        $store = Store::where('is_active', true)->first();

        if (! $store) {
            $this->error('Tidak ada toko aktif ditemukan — buat/aktifkan minimal 1 toko dulu, baru jalankan perintah ini lagi.');
            return self::FAILURE;
        }

        $employees = $this->createDemoEmployees($store);
        $this->createAttendances($employees, $store);
        $this->createLeaveRequests($employees, $store);
        $this->createPayrolls($employees);
        $this->createWarningLetters($employees, $store);
        $this->createContractExtensions($employees);

        $this->newLine();
        $this->info('Data dummy grup Karyawan selesai dibuat. Cek Filament: Karyawan.');
        $this->info('Kalau sudah selesai lihat-lihat, bersihkan datanya dengan: php artisan karyawan-group:demo --cleanup');

        return self::SUCCESS;
    }

    private function createDemoEmployees(Store $store): \Illuminate\Support\Collection
    {
        $profiles = [
            ['name' => 'Karyawan Demo Satu', 'joinMonthsAgo' => 18],
            ['name' => 'Karyawan Demo Dua', 'joinMonthsAgo' => 4],
        ];

        $created = collect();

        foreach ($profiles as $profile) {
            $emailLocal = str()->slug($profile['name']) . rand(10, 999);

            $user = User::create([
                'name' => self::MARK_PREFIX . $profile['name'],
                'email' => "{$emailLocal}" . self::DEMO_EMAIL_SUFFIX,
                'phone' => '08' . rand(11, 99) . rand(1000000, 9999999),
                'password' => Str::random(24),
                'store_id' => $store->id,
                'join_date' => Carbon::now()->subMonths($profile['joinMonthsAgo'])->startOfMonth(),
                'base_salary' => 4500000,
                'contract_end_date' => Carbon::now()->addMonths(6),
                'is_active' => true,
            ]);

            $user->syncRoles(['store_manager']);

            $created->push($user);
        }

        $this->info("{$created->count()} akun karyawan demo dibuat (role store_manager, toko: {$store->name}).");

        return $created;
    }

    /**
     * Attendance dibuat pakai entry_type 'manual' (admin input, bukan
     * clock via app) — bulan berjalan, campuran hadir tepat waktu, telat,
     * dan 1 hari alpha, supaya potongan di Payroll::generateForMonth()
     * ada isinya (bukan 0 semua).
     */
    private function createAttendances(\Illuminate\Support\Collection $employees, Store $store): void
    {
        $count = 0;
        $today = Carbon::today();
        $startOfMonth = $today->copy()->startOfMonth();

        foreach ($employees as $employee) {
            for ($day = $startOfMonth->copy(); $day->lte($today); $day->addDay()) {
                if ($day->isSunday()) {
                    continue;
                }

                // 1 hari acak jadi alpha (tanpa clock_in/out sama sekali).
                if ($day->day === 10 && $today->day >= 10) {
                    Attendance::create([
                        'user_id' => $employee->id,
                        'store_id' => $store->id,
                        'date' => $day->toDateString(),
                        'entry_type' => 'alpha',
                        'note' => self::MARK_PREFIX . 'Data dummy untuk demo/testing.',
                    ]);
                    $count++;
                    continue;
                }

                $lateMinutes = $day->day % 7 === 0 ? rand(20, 40) : 0;

                Attendance::create([
                    'user_id' => $employee->id,
                    'store_id' => $store->id,
                    'date' => $day->toDateString(),
                    'entry_type' => 'manual',
                    'clock_in_at' => $day->copy()->setTime(8, $lateMinutes > 0 ? 30 + $lateMinutes : 0),
                    'clock_out_at' => $day->copy()->setTime(17, 0),
                    'late_minutes' => $lateMinutes,
                    'note' => self::MARK_PREFIX . 'Data dummy untuk demo/testing.',
                ]);
                $count++;
            }
        }

        $this->info("{$count} baris absensi dummy dibuat (bulan berjalan).");
    }

    private function createLeaveRequests(\Illuminate\Support\Collection $employees, Store $store): void
    {
        $count = 0;
        $types = ['izin', 'sakit', 'cuti'];

        foreach ($employees as $i => $employee) {
            $type = $types[$i % count($types)];
            $start = Carbon::now()->subDays(rand(5, 20));
            $end = $start->copy()->addDays($type === 'cuti' ? 2 : 0);

            $request = new LeaveRequest([
                'user_id' => $employee->id,
                'store_id' => $store->id,
                'type' => $type,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'reason' => self::MARK_PREFIX . 'Alasan dummy untuk demo/testing.',
                'status' => 'approved',
                'reviewed_by' => null,
                'reviewed_at' => $start->copy()->addHours(2),
            ]);
            // request_number di-generate manual — booted()'s creating hook
            // tetap jalan normal di sini (tidak dibungkus withoutEvents()),
            // tapi tetap dijamin unik lewat generator asli.
            $request->save();

            $count++;
        }

        $this->info("{$count} pengajuan izin & cuti dummy dibuat (status disetujui langsung, tanpa push notification).");
    }

    /**
     * Payroll::generateForMonth() (logic asli) dipakai supaya
     * potongan/net_pay dihitung dari Attendance yang sudah dibuat, bukan
     * angka karangan — konsisten dengan alur admin generate payroll
     * sungguhan lewat Filament.
     */
    private function createPayrolls(\Illuminate\Support\Collection $employees): void
    {
        $count = 0;

        foreach ($employees as $employee) {
            try {
                Payroll::generateForMonth($employee, Carbon::now());
                $count++;
            } catch (\InvalidArgumentException $e) {
                $this->warn("Lewati payroll demo untuk {$employee->name}: {$e->getMessage()}");
            }
        }

        $this->info("{$count} payroll dummy digenerate (dari data absensi demo bulan ini, status draft).");
    }

    private function createWarningLetters(\Illuminate\Support\Collection $employees, Store $store): void
    {
        if ($employees->isEmpty()) {
            return;
        }

        $employee = $employees->first();

        $warning = new WarningLetter([
            'user_id' => $employee->id,
            'store_id' => $store->id,
            'level' => 'sp1',
            'reason' => self::MARK_PREFIX . 'Contoh alasan SP untuk demo/testing (mis. keterlambatan berulang).',
            'issued_date' => Carbon::now()->subDays(10)->toDateString(),
            'valid_until' => Carbon::now()->addMonths(6)->toDateString(),
            'issued_by' => null,
        ]);
        $warning->save();

        $this->info('1 surat peringatan dummy dibuat (SP 1).');
    }

    /**
     * ContractExtension::recordExtension() (logic asli) dipakai supaya
     * previous_end_date & sinkronisasi users.contract_end_date persis
     * sama seperti staff HR memperpanjang kontrak sungguhan lewat Filament.
     */
    private function createContractExtensions(\Illuminate\Support\Collection $employees): void
    {
        if ($employees->isEmpty()) {
            return;
        }

        $employee = $employees->last();
        $newEndDate = Carbon::now()->addYear()->toDateString();

        ContractExtension::recordExtension(
            $employee,
            $newEndDate,
            null,
            self::MARK_PREFIX . 'Perpanjangan dummy untuk demo/testing.'
        );

        $this->info('1 riwayat perpanjangan kontrak dummy dibuat.');
    }

    private function cleanup(): int
    {
        $demoUserIds = User::where('email', 'like', '%' . self::DEMO_EMAIL_SUFFIX)->pluck('id');

        if ($demoUserIds->isEmpty()) {
            $this->info('Tidak ada data demo untuk dibersihkan.');
            return self::SUCCESS;
        }

        $total = 0;
        // cascadeOnDelete() di migrations (Attendance, LeaveRequest,
        // Payroll, WarningLetter, ContractExtension — semua foreignId
        // user_id-nya cascade) berarti menghapus User demo SUDAH otomatis
        // menghapus semua riwayat HR terkait. Tetap dihitung eksplisit di
        // sini dulu (sebelum User dihapus) supaya angka yang dilaporkan
        // ke user akurat, bukan cuma jumlah User.
        $total += Attendance::whereIn('user_id', $demoUserIds)->count();
        $total += LeaveRequest::whereIn('user_id', $demoUserIds)->count();
        $total += Payroll::whereIn('user_id', $demoUserIds)->count();
        $total += WarningLetter::whereIn('user_id', $demoUserIds)->count();
        $total += ContractExtension::whereIn('user_id', $demoUserIds)->count();
        $total += $demoUserIds->count();

        User::whereIn('id', $demoUserIds)->delete();

        $this->info("{$total} baris data dummy grup Karyawan (termasuk akun karyawan demo) sudah dihapus.");

        return self::SUCCESS;
    }
}
