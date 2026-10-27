<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingQcResult;
use App\Models\BookingRework;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Technician;
use App\Models\TechnicianSkill;
use App\Models\TechnicianTraining;
use App\Models\User;
use App\Services\InstallerQualityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Data demo untuk menu Karyawan > Kompetensi Installer: matriks keterampilan per layanan, sertifikat pelatihan (ada yang kedaluwarsa dan yang
 * hampir kedaluwarsa), dan mutu pekerjaan (booking selesai dengan hasil Quality Check dan catatan pekerjaan ulang), dalam 90 hari terakhir.
 *
 * Lima installer demo dengan profil berbeda supaya tiap bagian halaman punya isi: satu mahir dan konsisten, satu mandiri dengan sertifikat
 * kedaluwarsa, satu pemula yang sering gagal QC, satu belum pernah dinilai, dan satu spesialis detailing. Semua baris ditandai: email
 * berakhiran @kompetensi.ginnva.test dan nomor booking berawalan DEMO-KMP-. Booking dibuat tanpa event (tidak ada push atau email ke staf asli),
 * dan pencatatan Histori Aktivitas dimatikan selama perintah berjalan supaya Histori tidak terisi data demo. Jangan jalankan di production
 * (dijaga DemoDataGuard).
 */
class DemoKompetensiInstaller extends Command
{
    use \App\Console\Commands\Concerns\DemoDataGuard;

    protected $signature = 'kompetensi:demo
        {--cleanup : Hapus semua data demo ini, bukan generate}';

    protected $description = 'Bikin data demo untuk menu Kompetensi Installer (matriks keterampilan, sertifikat, mutu pekerjaan)';

    private const EMAIL_SUFFIX = '@kompetensi.ginnva.test';
    private const BOOKING_PREFIX = 'DEMO-KMP-';
    private const CUSTOMER_NAME = 'Demo Pelanggan Kompetensi';

    /**
     * jobs/checked/first_pass = jumlah pekerjaan, yang diperiksa QC, dan yang lulus pada pemeriksaan pertama (sisanya gagal dulu baru lulus);
     * rework = berapa dari yang gagal QC dicatat juga sebagai Pekerjaan Ulang; trainings = [judul, penyelenggara, layanan, hari lalu, berlaku sampai (hari dari sekarang)].
     */
    private const INSTALLERS = [
        [
            'name' => 'Demo Rina Ahli', 'level' => 'mentor', 'commission' => 200000,
            'skills' => ['ppf' => TechnicianSkill::EXPERT, 'kaca_film' => TechnicianSkill::EXPERT, 'detailing' => TechnicianSkill::INDEPENDENT, 'premium_wash' => TechnicianSkill::INDEPENDENT],
            'trainings' => [
                ['Sertifikasi Pemasangan PPF Lanjutan', 'Akademi Film Indonesia', 'ppf', 200, 165],
                ['Pelatihan Kaca Film Premium', 'Ginnva Training Center', 'kaca_film', 340, 20],
            ],
            'jobs' => 12, 'checked' => 11, 'first_pass' => 10, 'rework' => 1,
        ],
        [
            'name' => 'Demo Dani Mandiri', 'level' => 'advanced', 'commission' => 150000,
            'skills' => ['ppf' => TechnicianSkill::INDEPENDENT, 'kaca_film' => TechnicianSkill::INDEPENDENT, 'detailing' => TechnicianSkill::BEGINNER],
            'trainings' => [
                ['Keselamatan Kerja Bengkel', 'Ginnva Training Center', null, 400, -15],
                ['Dasar Pemasangan Kaca Film', 'Ginnva Training Center', 'kaca_film', 250, 115],
            ],
            'jobs' => 8, 'checked' => 7, 'first_pass' => 5, 'rework' => 1,
        ],
        [
            'name' => 'Demo Eko Pemula', 'level' => 'intermediate', 'commission' => 100000,
            'skills' => ['ppf' => TechnicianSkill::BEGINNER, 'kaca_film' => TechnicianSkill::BEGINNER, 'detailing' => TechnicianSkill::BEGINNER],
            'trainings' => [
                ['Orientasi Teknisi Baru', 'Ginnva Training Center', null, 90, 275],
            ],
            'jobs' => 6, 'checked' => 6, 'first_pass' => 3, 'rework' => 2,
        ],
        [
            'name' => 'Demo Fajar Baru', 'level' => 'intermediate', 'commission' => null,
            'skills' => [],
            'trainings' => [],
            'jobs' => 2, 'checked' => 0, 'first_pass' => 0, 'rework' => 0,
        ],
        [
            'name' => 'Demo Gita Detailing', 'level' => 'advanced', 'commission' => 150000,
            'skills' => ['detailing' => TechnicianSkill::EXPERT, 'premium_wash' => TechnicianSkill::EXPERT],
            'trainings' => [
                ['Ceramic dan Paint Correction', 'Akademi Detailing Nusantara', 'detailing', 160, 205],
            ],
            'jobs' => 7, 'checked' => 7, 'first_pass' => 7, 'rework' => 0,
        ],
    ];

    public function handle(): int
    {
        if ($this->refuseOutsideDemoEnvironment()) {
            return self::FAILURE;
        }

        $logging = method_exists(activity(), 'disableLogging');
        $logging && activity()->disableLogging();

        try {
            // Aman dijalankan ulang: data demo lama selalu dibersihkan dulu.
            $removed = $this->cleanup();

            if ($this->option('cleanup')) {
                $this->info("Data demo Kompetensi Installer dihapus ({$removed['users']} installer, {$removed['bookings']} booking).");

                return self::SUCCESS;
            }

            return $this->generate();
        } finally {
            $logging && activity()->enableLogging();
        }
    }

    private function generate(): int
    {
        $store = Store::where('is_active', true)->orderBy('id')->first();

        if (! $store) {
            $this->error('Belum ada toko aktif; buat toko dulu.');

            return self::FAILURE;
        }

        Role::findOrCreate('installer', 'web');
        $inspectorId = User::role('super_admin')->value('id');
        $customer = Customer::create(['name' => self::CUSTOMER_NAME, 'phone_number' => '0899' . random_int(10000000, 99999999)]);

        $users = [];
        $sequence = 0;

        foreach (self::INSTALLERS as $definition) {
            $user = User::create([
                'name' => $definition['name'],
                'email' => Str::slug($definition['name']) . self::EMAIL_SUFFIX,
                'phone' => '08' . random_int(11, 99) . random_int(1000000, 9999999),
                'password' => Str::random(24),
                'store_id' => $store->id,
                'join_date' => now()->subMonths(random_int(8, 30))->startOfMonth(),
                'base_salary' => 4000000,
                'is_active' => true,
            ]);
            $user->syncRoles(['installer']);

            $technician = Technician::create([
                'store_id' => $store->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'level' => $definition['level'],
                'commission_amount' => $definition['commission'],
                'status' => 'active',
                'notes' => 'Data demo Kompetensi Installer.',
            ]);

            foreach ($definition['skills'] as $service => $level) {
                TechnicianSkill::create(['technician_id' => $technician->id, 'service_type' => $service, 'level' => $level, 'assessed_on' => now()->subDays(45)->toDateString(), 'notes' => 'Penilaian demo.']);
            }

            foreach ($definition['trainings'] as [$title, $provider, $service, $daysAgo, $validDays]) {
                TechnicianTraining::create([
                    'technician_id' => $technician->id, 'title' => $title, 'provider' => $provider, 'service_type' => $service,
                    'held_on' => now()->subDays($daysAgo)->toDateString(), 'valid_until' => now()->addDays($validDays)->toDateString(), 'notes' => 'Data demo.',
                ]);
            }

            $users[$definition['name']] = $user;
        }

        foreach (self::INSTALLERS as $definition) {
            $this->makeJobs($users[$definition['name']], $definition, $store, $customer, $inspectorId, $sequence);
        }

        // Pekerjaan tim: dua installer di satu booking, semuanya lulus QC (keduanya dihitung sama).
        for ($i = 0; $i < 3; $i++) {
            $booking = $this->makeBooking($store, $customer, [$users['Demo Rina Ahli'], $users['Demo Eko Pemula']], now()->subDays(6 + $i * 9), ++$sequence);
            BookingQcResult::create(['booking_id' => $booking->id, 'inspector_user_id' => $inspectorId, 'passed' => true]);
        }

        $this->info('Data demo Kompetensi Installer dibuat di toko "' . $store->name . '": ' . count(self::INSTALLERS) . ' installer, ' . $sequence . ' booking selesai.');
        $this->renderSummary($store->id);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $definition */
    private function makeJobs(User $user, array $definition, Store $store, Customer $customer, ?int $inspectorId, int &$sequence): void
    {
        for ($i = 0; $i < $definition['jobs']; $i++) {
            $date = now()->subDays(2 + intdiv(84 * $i, max(1, $definition['jobs'])));
            $booking = $this->makeBooking($store, $customer, [$user], $date, ++$sequence);

            if ($i < $definition['first_pass']) {
                BookingQcResult::create(['booking_id' => $booking->id, 'inspector_user_id' => $inspectorId, 'passed' => true]);
            } elseif ($i < $definition['checked']) {
                // Gagal dulu, lalu lulus setelah diperbaiki.
                BookingQcResult::create(['booking_id' => $booking->id, 'inspector_user_id' => $inspectorId, 'passed' => false, 'notes' => 'Tepi film belum rapi (demo).']);
                BookingQcResult::create(['booking_id' => $booking->id, 'inspector_user_id' => $inspectorId, 'passed' => true]);

                if ($i - $definition['first_pass'] < $definition['rework']) {
                    BookingRework::create([
                        'booking_id' => $booking->id, 'source' => 'qc_gagal', 'reason' => 'Pemasangan diulang setelah QC gagal (demo).',
                        'reported_on' => $date->toDateString(), 'resolved_on' => $date->copy()->addDay()->toDateString(),
                    ]);
                }
            }
        }
    }

    /** @param list<User> $installers */
    private function makeBooking(Store $store, Customer $customer, array $installers, \Illuminate\Support\Carbon $date, int $number): Booking
    {
        $booking = Booking::withoutEvents(fn () => Booking::create([
            'booking_number' => self::BOOKING_PREFIX . str_pad((string) $number, 4, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'store_id' => $store->id,
            'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true,
            'preferred_date' => $date->toDateString(),
            'preferred_time' => '09:00',
            'status' => 'completed',
        ]));

        $booking->installers()->sync(collect($installers)->pluck('id')->all());

        return $booking;
    }

    private function renderSummary(int $storeId): void
    {
        $rows = app(InstallerQualityService::class)->forPeriod(now()->subDays(89), now(), $storeId)
            ->filter(fn (array $row) => str_starts_with($row['name'], 'Demo '))
            ->map(fn (array $row) => [$row['name'], $row['jobs'], $row['checked'], $row['first_pass'], $row['first_pass_rate'] === null ? '-' : $row['first_pass_rate'] . '%', $row['reworked'], $row['rework_recorded']])
            ->all();

        $this->table(['Installer', 'Pekerjaan', 'Diperiksa QC', 'Lulus pertama', '% lulus', 'Pernah gagal', 'Ulang dicatat'], $rows);
        $this->line('Buka: Karyawan > Data Karyawan > Kompetensi Installer (pilih rentang 90 hari terakhir).');
    }

    /** @return array{users: int, bookings: int} */
    private function cleanup(): array
    {
        $userIds = User::where('email', 'like', '%' . self::EMAIL_SUFFIX)->pluck('id')->all();
        $technicianIds = Technician::withoutGlobalScopes()->whereIn('user_id', $userIds)->pluck('id')->all();
        $bookingIds = Booking::withoutGlobalScopes()->where('booking_number', 'like', self::BOOKING_PREFIX . '%')->pluck('id')->all();

        BookingRework::withoutGlobalScopes()->whereIn('booking_id', $bookingIds)->delete();
        BookingQcResult::whereIn('booking_id', $bookingIds)->delete();
        DB::table('booking_installers')->whereIn('booking_id', $bookingIds)->delete();
        DB::table((new Booking())->getTable())->whereIn('id', $bookingIds)->delete();

        TechnicianSkill::whereIn('technician_id', $technicianIds)->delete();
        TechnicianTraining::whereIn('technician_id', $technicianIds)->delete();
        DB::table((new Technician())->getTable())->whereIn('id', $technicianIds)->delete();

        DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
        DB::table((new User())->getTable())->whereIn('id', $userIds)->delete();
        DB::table((new Customer())->getTable())->where('name', self::CUSTOMER_NAME)->delete();

        return ['users' => count($userIds), 'bookings' => count($bookingIds)];
    }
}
