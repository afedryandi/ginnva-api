<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\EmployeeScheduleAssignment;
use App\Models\FilmProduct;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Invoice;
use App\Models\Payable;
use App\Models\Receivable;
use App\Models\RecurringBillTemplate;
use App\Models\Shift;
use App\Models\SpendPromo;
use App\Models\Spk;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\BookingPostingService;
use App\Services\DownPaymentService;
use App\Services\FinanceTransactionPostingService;
use App\Services\InvoiceService;
use App\Services\PayableService;
use App\Services\ReceivableService;
use App\Services\RefundService;
use App\Services\SpkService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Data demo terpadu untuk PRESENTASI ke atasan (server dev). Melengkapi perintah demo per-grup yang sudah ada
 * (booking-group, inventaris-group, karyawan-group, marketing-group, rewards-group, quotations) dengan data yang
 * selama ini kosong: Penjualan (booking selesai bernominal + jurnal pendapatan + piutang + DP + refund), Keuangan
 * (transaksi, hutang, invoice, mutasi bank, tagihan rutin), SPK, Jadwal Kerja, Grup Pelanggan, Promo Belanja.
 *
 * Semua baris ditandai awalan "DEMO"/"[DEMO]" atau akhiran email "@presentasi.ginnva.test". Jurnal yang dibuat
 * service dibersihkan lewat snapshot id jurnal (storage/app/demo-presentasi.json), jadi JANGAN jalankan di
 * production dan jangan memposting jurnal asli di antara generate dan --cleanup.
 *
 * Tanpa efek samping nyata: Booking/Spk/Invoice dibuat lewat withoutEvents() (tidak ada email/push ke staff asli).
 */
class DemoPresentasi extends Command
{
    protected $signature = 'demo:presentasi
        {--bookings=48 : Jumlah booking dummy}
        {--skip-others : Lewati perintah demo per-grup yang sudah ada}
        {--cleanup : Hapus semua data demo (termasuk perintah demo per-grup), bukan generate}';

    protected $description = 'Bikin data demo lengkap lintas menu (Penjualan, Keuangan, SPK, Jadwal Kerja, dll.) untuk presentasi';

    private const BOOKING_PREFIX = 'DEMO-PRS-';
    private const TAG = '[DEMO]';
    private const EMAIL_SUFFIX = '@presentasi.ginnva.test';
    private const STATE_FILE = 'demo-presentasi.json';

    private const NAMES = ['Budi Santoso', 'Siti Rahmawati', 'Andi Wijaya', 'Rina Kurniawati', 'Dedi Saputra', 'Wulan Pratama', 'Hendra Halim', 'Sri Setiawan', 'Agus Gunawan', 'Dewi Hartono', 'Rizky Susanto', 'Putri Anggraini'];
    private const CARS = ['Toyota Fortuner', 'Honda CR-V', 'Mitsubishi Pajero', 'Toyota Alphard', 'Honda HR-V', 'Hyundai Palisade', 'Toyota Innova Zenix', 'Mazda CX-5'];

    /** @var array<int, array<string, mixed>> */
    private array $failures = [];

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        if (app()->environment('production')) {
            $this->error('Perintah ini hanya untuk server dev/demo, tidak boleh dijalankan di production.');

            return self::FAILURE;
        }

        if (Booking::withoutGlobalScopes()->where('booking_number', 'like', self::BOOKING_PREFIX . '%')->exists()) {
            $this->error('Data demo sudah ada. Jalankan dulu: php artisan demo:presentasi --cleanup');

            return self::FAILURE;
        }

        $stores = Store::where('is_active', true)->get();
        if ($stores->isEmpty() || ! ChartOfAccount::where('code', '1101')->exists()) {
            $this->error('Butuh minimal 1 toko aktif dan Bagan Akun terisi (seeder ChartOfAccountSeeder + FinanceCategorySeeder).');

            return self::FAILURE;
        }

        File::put(storage_path('app/' . self::STATE_FILE), json_encode([
            'journal_start_id' => (int) DB::table('journal_entries')->max('id'),
            'created_at' => now()->toDateTimeString(),
        ]));

        $ctx = (object) ['stores' => $stores, 'customers' => collect(), 'installers' => [], 'bookings' => collect()];

        $this->step('Grup pelanggan & pelanggan demo', fn () => $this->customers($ctx));
        $this->step('Teknisi/installer demo', fn () => $this->installers($ctx));
        $this->step('Booking selesai + jurnal pendapatan + piutang', fn () => $this->bookings($ctx, max(10, (int) $this->option('bookings'))));
        $this->step('Booking berjalan + DP + SPK', fn () => $this->upcomingBookings($ctx));
        $this->step('Refund', fn () => $this->refunds($ctx));
        $this->step('Pelunasan piutang', fn () => $this->receivablePayments());
        $this->step('Transaksi keuangan (pemasukan & pengeluaran)', fn () => $this->financeTransactions($ctx));
        $this->step('Supplier & hutang usaha', fn () => $this->payables($ctx));
        $this->step('Tagihan rutin', fn () => $this->recurringBill($ctx));
        $this->step('Invoice', fn () => $this->invoices($ctx));
        $this->step('Mutasi bank (rekonsiliasi)', fn () => $this->bankLines());
        $this->step('Promo belanja', fn () => $this->spendPromo());
        $this->step('Shift & jadwal kerja', fn () => $this->schedules($ctx));

        if (! $this->option('skip-others')) {
            foreach ([
                ['quotations:demo', []],
                ['booking-group:demo', ['--count' => 12]],
                ['inventaris-group:demo', []],
                ['karyawan-group:demo', []],
                ['marketing-group:demo', []],
                ['rewards-group:demo', []],
            ] as [$command, $args]) {
                $this->step("Perintah {$command}", fn () => $this->call($command, $args));
            }
        }

        $this->newLine();
        if ($this->failures) {
            $this->warn(count($this->failures) . ' langkah gagal (lihat pesan di atas). Langkah lain tetap tersimpan.');
        } else {
            $this->info('Selesai: data demo presentasi lengkap dibuat.');
        }
        $this->info('Bersihkan nanti dengan: php artisan demo:presentasi --cleanup');

        return self::SUCCESS;
    }

    private function step(string $label, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->failures[] = ['step' => $label, 'error' => $e->getMessage()];
            $this->error("GAGAL [{$label}]: " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        }
    }

    private function rounded(int $min, int $max): int
    {
        return (int) (round(random_int($min, $max) / 50000) * 50000);
    }

    private function plate(): string
    {
        return chr(random_int(65, 90)) . ' ' . random_int(1000, 9999) . ' ' . chr(random_int(65, 90)) . chr(random_int(65, 90));
    }

    private function customers(object $ctx): void
    {
        $group = CustomerGroup::firstOrCreate(['name' => 'Demo Member Gold'], ['description' => 'Grup contoh untuk harga khusus (data demo).', 'is_active' => true, 'sort_order' => 1]);

        foreach (array_slice(self::NAMES, 0, 10) as $i => $name) {
            $customer = Customer::create([
                'name' => $name,
                'email' => Str::slug($name) . random_int(10, 99) . self::EMAIL_SUFFIX,
                'phone_number' => '08' . random_int(11, 99) . random_int(1000000, 9999999),
                'customer_group_id' => $i < 3 ? $group->id : null,
                'gender' => $i % 2 ? 'female' : 'male',
                'loyalty_points' => random_int(0, 6) * 100,
            ]);
            $ctx->customers->push($customer);
        }

        $this->info($ctx->customers->count() . ' pelanggan demo + 1 grup pelanggan dibuat.');
    }

    private function installers(object $ctx): void
    {
        foreach ($ctx->stores as $store) {
            foreach ([['Demo Installer Satu', 150000, 'advanced'], ['Demo Installer Dua', 200000, 'mentor']] as [$name, $commission, $level]) {
                $user = User::create([
                    'name' => $name . ' ' . $store->id,
                    'email' => 'installer' . $store->id . Str::slug($name) . self::EMAIL_SUFFIX,
                    'phone' => '08' . random_int(11, 99) . random_int(1000000, 9999999),
                    'password' => Str::random(24),
                    'store_id' => $store->id,
                    'join_date' => now()->subMonths(random_int(6, 30))->startOfMonth(),
                    'base_salary' => 4000000,
                    'is_active' => true,
                ]);
                $user->syncRoles(['installer']);

                Technician::create([
                    'store_id' => $store->id,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'level' => $level,
                    'commission_amount' => $commission,
                    'status' => 'active',
                    'notes' => 'Data demo.',
                ]);

                $ctx->installers[$store->id][] = $user;
            }
        }

        $this->info('Installer & teknisi demo dibuat.');
    }

    /**
     * Booking selesai tersebar ±90 hari ke belakang. Jurnal diposting lewat BookingPostingService (sama persis alur
     * "Proses Referral"), lalu tanggal jurnal digeser mundur ke tanggal booking supaya grafik tren terisi.
     */
    private function bookings(object $ctx, int $count): void
    {
        $completedCount = (int) round($count * 0.8);
        $films = FilmProduct::query()->limit(30)->get();
        $posting = app(BookingPostingService::class);
        $made = 0;

        $services = [
            ['ppf' => true, 'kf' => false, 'det' => false, 'wash' => false, 'label' => 'PPF', 'min' => 9000000, 'max' => 26000000],
            ['ppf' => false, 'kf' => true, 'det' => false, 'wash' => false, 'label' => 'Kaca Film', 'min' => 2500000, 'max' => 9000000],
            ['ppf' => true, 'kf' => true, 'det' => false, 'wash' => false, 'label' => 'PPF + Kaca Film', 'min' => 14000000, 'max' => 32000000],
            ['ppf' => false, 'kf' => false, 'det' => true, 'wash' => false, 'label' => 'Detailing', 'min' => 800000, 'max' => 3500000],
            ['ppf' => false, 'kf' => false, 'det' => false, 'wash' => true, 'label' => 'Premium Wash', 'min' => 300000, 'max' => 900000],
        ];
        $serviceWeights = [0, 0, 0, 1, 1, 1, 1, 2, 3, 3, 4];
        $methods = ['tunai', 'transfer', 'transfer', 'qris', 'qris', 'edc'];

        for ($i = 0; $i < $completedCount; $i++) {
            $store = $ctx->stores->random();
            $customer = $ctx->customers->random();
            $svc = $services[$serviceWeights[array_rand($serviceWeights)]];
            $daysAgo = (int) floor(pow(random_int(0, 1000) / 1000, 0.8) * 88) + 1;
            $date = Carbon::now()->subDays($daysAgo);
            $amount = $this->rounded($svc['min'], $svc['max']);
            $partial = $i % 7 === 3;
            $received = $partial ? round($amount * 0.6, -3) : $amount;
            $dpp = (int) round($amount / (1 + Booking::PPN_RATE));

            $booking = Booking::withoutEvents(function () use ($customer, $store, $svc, $date, $amount, $received, $dpp, $methods, $films) {
                $b = new Booking([
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'phone_number' => $customer->phone_number,
                    'store_id' => $store->id,
                    'service_type' => $svc['label'],
                    'product_ppf' => $svc['ppf'],
                    'product_kaca_film' => $svc['kf'],
                    'product_detailing' => $svc['det'],
                    'product_premium_wash' => $svc['wash'],
                    'film_product_id' => ($svc['ppf'] || $svc['kf']) && $films->isNotEmpty() ? $films->random()->id : null,
                    'vehicle_size' => ['S', 'M', 'L', 'XL'][array_rand(['S', 'M', 'L', 'XL'])],
                    'preferred_date' => $date->toDateString(),
                    'preferred_time' => ['09:00 - 11:00', '11:00 - 13:00', '13:00 - 15:00'][array_rand([0, 1, 2])],
                    'duration_days' => $svc['ppf'] ? Booking::DEFAULT_DURATION_DAYS_PPF : Booking::DEFAULT_DURATION_DAYS_DEFAULT,
                    'notes' => self::TAG . ' ' . self::CARS[array_rand(self::CARS)] . ' - ' . $this->plate(),
                    'source' => ['walk_in', 'walk_in', 'app', 'whatsapp'][array_rand([0, 1, 2, 3])],
                    'status' => 'completed',
                    'current_stage' => 'completed',
                    'transaction_amount' => $amount,
                    'amount_received' => $received,
                    'dpp_amount' => $dpp,
                    'ppn_amount' => $amount - $dpp,
                    'payment_method' => $methods[array_rand($methods)],
                ]);
                $b->booking_number = self::BOOKING_PREFIX . $date->format('ymd') . '-' . Str::upper(Str::random(4));
                $b->save();

                return $b;
            });

            $installers = collect($ctx->installers[$store->id] ?? []);
            if ($installers->isNotEmpty()) {
                $booking->installers()->syncWithoutDetaching($installers->random(random_int(1, min(2, $installers->count())))->pluck('id')->all());
            }

            Booking::withoutEvents(fn () => $posting->sync($booking->refresh()));

            $booking->refresh();
            $stamp = $date->copy()->setTime(random_int(9, 16), random_int(0, 59));
            DB::table('bookings')->where('id', $booking->id)->update(['created_at' => $stamp, 'updated_at' => $stamp]);
            if ($booking->journal_entry_id) {
                DB::table('journal_entries')->where('id', $booking->journal_entry_id)->update(['entry_date' => $date->toDateString(), 'created_at' => $stamp, 'updated_at' => $stamp]);
                DB::table('receivables')->where('journal_entry_id', $booking->journal_entry_id)->update(['due_date' => $date->copy()->addDays(14)->toDateString(), 'created_at' => $stamp]);
            }

            $ctx->bookings->push($booking);
            $made++;
        }

        $this->info("{$made} booking selesai (dengan jurnal pendapatan, PPN, metode bayar, installer) dibuat.");
    }

    private function upcomingBookings(object $ctx): void
    {
        $dp = app(DownPaymentService::class);
        $n = 0;

        foreach (range(1, 6) as $i) {
            $store = $ctx->stores->random();
            $customer = $ctx->customers->random();
            $ppf = $i % 2 === 0;
            $status = $i <= 4 ? 'confirmed' : 'pending';

            $booking = Booking::withoutEvents(function () use ($customer, $store, $ppf, $i, $status) {
                $b = new Booking([
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'phone_number' => $customer->phone_number,
                    'store_id' => $store->id,
                    'service_type' => $ppf ? 'PPF' : 'Kaca Film',
                    'product_ppf' => $ppf,
                    'product_kaca_film' => ! $ppf,
                    'vehicle_size' => ['M', 'L', 'XL'][array_rand([0, 1, 2])],
                    'preferred_date' => now()->addDays($i)->toDateString(),
                    'preferred_time' => '09:00 - 11:00',
                    'duration_days' => $ppf ? Booking::DEFAULT_DURATION_DAYS_PPF : Booking::DEFAULT_DURATION_DAYS_DEFAULT,
                    'notes' => self::TAG . ' ' . self::CARS[array_rand(self::CARS)] . ' - ' . $this->plate(),
                    'source' => 'whatsapp',
                    'status' => $status,
                    'current_stage' => $status === 'confirmed' ? ($ppf ? 'ppf_washing' : 'kf_cleaning') : null,
                ]);
                $b->booking_number = self::BOOKING_PREFIX . now()->format('ymd') . '-U' . $i . Str::upper(Str::random(3));
                $b->save();

                return $b;
            });

            if ($status === 'confirmed') {
                $installers = collect($ctx->installers[$store->id] ?? []);
                if ($installers->isNotEmpty()) {
                    $booking->installers()->syncWithoutDetaching([$installers->random()->id]);
                }
                if ($i <= 3) {
                    Booking::withoutEvents(fn () => $dp->receive($booking, $this->rounded(500000, 2000000), 'DP awal (data demo)', null));
                }
                if ($i <= 3) {
                    Spk::withoutEvents(fn () => app(SpkService::class)->createFromBooking($booking->refresh(), null));
                }
            }

            $ctx->bookings->push($booking);
            $n++;
        }

        // Satu booking dibatalkan agar status "Dibatalkan" tampil di daftar.
        Booking::withoutEvents(function () use ($ctx) {
            $b = new Booking([
                'customer_id' => $ctx->customers->first()->id,
                'customer_name' => $ctx->customers->first()->name,
                'phone_number' => $ctx->customers->first()->phone_number,
                'store_id' => $ctx->stores->first()->id,
                'service_type' => 'Kaca Film',
                'product_kaca_film' => true,
                'preferred_date' => now()->subDays(2)->toDateString(),
                'preferred_time' => '13:00 - 15:00',
                'duration_days' => 1,
                'notes' => self::TAG . ' dibatalkan customer',
                'source' => 'app',
                'status' => 'cancelled',
                'cancel_reason' => 'Jadwal bentrok, customer minta dijadwal ulang (data demo).',
                'cancelled_at' => now()->subDays(3),
            ]);
            $b->booking_number = self::BOOKING_PREFIX . now()->format('ymd') . '-X' . Str::upper(Str::random(3));
            $b->save();
        });

        $this->info("{$n} booking berjalan (konfirmasi/pending) + DP + SPK + 1 booking batal dibuat.");
    }

    private function refunds(object $ctx): void
    {
        $service = app(RefundService::class);
        $candidates = $ctx->bookings->filter(fn (Booking $b) => $b->status === 'completed' && $b->journal_entry_id && (float) $b->amount_received >= (float) $b->transaction_amount)->take(2);

        foreach ($candidates as $b) {
            $service->process($b, round((float) $b->transaction_amount * 0.15, -3), 'Sebagian produk dibatalkan atas permintaan customer (data demo).', null);
        }

        $this->info($candidates->count() . ' refund dibuat.');
    }

    private function receivablePayments(): void
    {
        $service = app(ReceivableService::class);
        $receivables = Receivable::withoutGlobalScopes()
            ->where('source_type', 'booking')
            ->whereIn('source_id', Booking::withoutGlobalScopes()->where('booking_number', 'like', self::BOOKING_PREFIX . '%')->pluck('id'))
            ->whereIn('status', ['unpaid', 'partial'])
            ->orderBy('id')
            ->get();

        $paid = 0;
        foreach ($receivables->take(2) as $r) {
            $service->recordPayment($r, round(((float) $r->amount - (float) $r->amount_paid) * 0.5, -3), now(), null, 'Pelunasan sebagian (data demo)');
            $paid++;
        }

        $this->info($receivables->count() . " piutang usaha dari booking ({$paid} sudah dicicil).");
    }

    private function financeTransactions(object $ctx): void
    {
        $out = FinanceCategory::where('type', 'out')->where('is_group', false)->whereNotNull('chart_of_account_id')->get();
        $in = FinanceCategory::where('type', 'in')->where('is_group', false)->whereNotNull('chart_of_account_id')->get();
        if ($out->isEmpty()) {
            throw new \RuntimeException('Belum ada Kategori Keuangan pengeluaran yang terhubung ke akun. Jalankan FinanceCategorySeeder.');
        }

        $posting = app(FinanceTransactionPostingService::class);
        $n = 0;

        for ($d = 85; $d >= 1; $d -= 3) {
            $date = Carbon::now()->subDays($d);
            $isIn = $in->isNotEmpty() && $n % 5 === 4;
            $category = $isIn ? $in->random() : $out->random();

            $transaction = FinanceTransaction::create([
                'type' => $isIn ? 'in' : 'out',
                'finance_category_id' => $category->id,
                'store_id' => $ctx->stores->random()->id,
                'amount' => $isIn ? $this->rounded(500000, 4000000) : $this->rounded(150000, 6500000),
                'transaction_date' => $date->toDateString(),
                'description' => self::TAG . ' ' . $category->name . ' (contoh)',
            ]);

            $entry = $posting->post($transaction);
            $transaction->update(['journal_entry_id' => $entry->id]);
            $n++;
        }

        $this->info("{$n} transaksi keuangan (pemasukan & pengeluaran, terposting ke jurnal) dibuat.");
    }

    private function payables(object $ctx): void
    {
        $debit = ChartOfAccount::whereIn('type', ['beban_operasional', 'beban_pokok'])->where('is_postable', true)->where('is_active', true)->orderBy('code')->get();
        if ($debit->isEmpty()) {
            throw new \RuntimeException('Tidak ada akun beban aktif di Bagan Akun untuk didebit.');
        }

        $suppliers = collect([
            ['Demo PT Sinar Film Nusantara', 'Bank BCA', '8801234567'],
            ['Demo CV Mitra Kimia Otomotif', 'Bank Mandiri', '1400098765'],
            ['Demo Toko Peralatan Detailing Jaya', 'Bank BRI', '0123456789'],
        ])->map(fn ($s) => Supplier::create([
            'name' => $s[0], 'bank_name' => $s[1], 'bank_account_number' => $s[2], 'bank_account_name' => $s[0],
            'phone' => '08' . random_int(11, 99) . random_int(1000000, 9999999), 'email' => Str::slug($s[0]) . self::EMAIL_SUFFIX,
            'notes' => self::TAG, 'is_active' => true,
        ]));

        $service = app(PayableService::class);
        $payables = [];
        foreach ([[-40, -10, 14500000], [-25, 5, 8750000], [-12, 18, 21300000], [-6, 25, 3200000], [-30, -2, 6900000], [-3, 30, 11750000]] as $i => [$entryOffset, $dueOffset, $amount]) {
            $supplier = $suppliers[$i % $suppliers->count()];
            $payables[] = $service->createWithJournal([
                'supplier_name' => $supplier->name,
                'supplier_id' => $supplier->id,
                'invoice_number' => 'INV-DEMO-' . random_int(1000, 9999),
                'store_id' => $ctx->stores->random()->id,
                'amount' => $amount,
                'due_date' => now()->addDays($dueOffset)->toDateString(),
                'notes' => self::TAG . ' Pembelian bahan/peralatan (contoh)',
                'created_by' => null,
                'entry_date' => now()->addDays($entryOffset)->toDateString(),
            ], $debit->random()->id);
        }

        $service->recordPayment($payables[0], 14500000, now(), null, 'Lunas via transfer (data demo)');
        $service->recordPayment($payables[1], 3000000, now(), null, 'Cicilan pertama (data demo)');

        $this->info('3 supplier + ' . count($payables) . ' hutang usaha (lunas/sebagian/jatuh tempo) dibuat.');
    }

    private function recurringBill(object $ctx): void
    {
        $account = ChartOfAccount::whereIn('type', ['beban_operasional'])->where('is_postable', true)->where('is_active', true)->where('name', 'like', '%ewa%')->first()
            ?? ChartOfAccount::where('type', 'beban_operasional')->where('is_postable', true)->where('is_active', true)->first();

        if (! $account) {
            throw new \RuntimeException('Tidak ada akun beban untuk tagihan rutin.');
        }

        RecurringBillTemplate::create([
            'name' => self::TAG . ' Sewa Ruko Bulanan',
            'supplier_name' => 'Demo Pemilik Ruko',
            'store_id' => $ctx->stores->first()->id,
            'chart_of_account_id' => $account->id,
            'amount' => 12500000,
            'day_of_month' => 1,
            'next_run_date' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
            'is_active' => true,
            'notes' => 'Contoh tagihan rutin (data demo).',
        ]);

        $this->info('1 template tagihan rutin dibuat.');
    }

    private function invoices(object $ctx): void
    {
        $service = app(InvoiceService::class);
        $made = [];

        foreach ([['unpaid', 4], ['unpaid', 12], ['partial', 20], ['paid', 30]] as $i => [$status, $daysAgo]) {
            $customer = $ctx->customers[$i];
            $store = $ctx->stores->random();
            $price = $this->rounded(1500000, 18000000);

            $invoice = Invoice::withoutEvents(fn () => $service->create([
                'store_id' => $store->id,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone_number,
                'customer_email' => $customer->email,
                'billing_address' => 'Jl. Contoh No. ' . random_int(1, 99) . ', Jakarta (data demo)',
                'issue_date' => now()->subDays($daysAgo)->toDateString(),
                'due_date' => now()->subDays($daysAgo)->addDays(14)->toDateString(),
                'notes' => self::TAG,
            ], [
                ['name' => 'Jasa pemasangan PPF full body', 'quantity' => 1, 'unit' => 'paket', 'price' => $price, 'discount_percent' => 0],
                ['name' => 'Paket coating & detailing', 'quantity' => 1, 'unit' => 'paket', 'price' => 1200000, 'discount_percent' => 0],
            ], null, 'unpaid'));

            if ($status === 'paid') {
                Invoice::withoutEvents(fn () => $service->markPaid($invoice));
            } elseif ($status === 'partial') {
                Invoice::withoutEvents(fn () => $service->recordPayment($invoice, round((float) $invoice->total * 0.4, -3)));
            }
            $made[] = $invoice;
        }

        $this->info(count($made) . ' invoice (belum bayar/sebagian/lunas) dibuat.');
    }

    private function bankLines(): void
    {
        $cash = ChartOfAccount::where('code', '1101')->first();
        $n = 0;

        foreach ([[-20, 'Transfer masuk pelanggan', 18500000], [-17, 'Setoran tunai kasir', 6400000], [-14, 'Pembayaran supplier film', -14500000], [-10, 'Biaya admin bank', -15000], [-8, 'Transfer masuk QRIS settlement', 9250000], [-5, 'Listrik & air bulanan', -3150000], [-3, 'Transfer masuk pelanggan', 12000000]] as $i => [$offset, $desc, $amount]) {
            BankStatementLine::create([
                'chart_of_account_id' => $cash->id,
                'statement_date' => now()->addDays($offset)->toDateString(),
                'description' => self::TAG . ' ' . $desc,
                'amount' => $amount,
                'external_reference' => 'DEMO-STMT-' . ($i + 1),
                'status' => 'unmatched',
            ]);
            $n++;
        }

        $this->info("{$n} baris mutasi bank (belum dicocokkan) dibuat.");
    }

    private function spendPromo(): void
    {
        SpendPromo::create([
            'name' => self::TAG . ' Promo Belanja Rp5 Juta Potongan Rp250 Ribu',
            'description' => 'Contoh promo per total pembelian (data demo).',
            'min_purchase_amount' => 5000000,
            'discount_amount' => 250000,
            'starts_on' => now()->subDays(30)->toDateString(),
            'ends_on' => now()->addDays(30)->toDateString(),
            'is_active' => true,
        ]);

        $this->info('1 promo belanja dibuat.');
    }

    private function schedules(object $ctx): void
    {
        $n = 0;

        foreach ($ctx->stores as $store) {
            $pagi = Shift::create(['store_id' => $store->id, 'name' => 'Demo Shift Pagi', 'start_time' => '08:00', 'end_time' => '16:00', 'break_start_time' => '12:00', 'break_end_time' => '13:00', 'color' => '#2563eb', 'is_active' => true]);
            $siang = Shift::create(['store_id' => $store->id, 'name' => 'Demo Shift Siang', 'start_time' => '12:00', 'end_time' => '20:00', 'break_start_time' => '15:00', 'break_end_time' => '16:00', 'color' => '#d97706', 'is_active' => true]);

            $days = collect(WorkSchedule::DAYS)->map(fn (string $day) => [
                'day' => $day,
                'shift_id' => $day === 'sun' ? null : ($day === 'sat' ? $siang->id : $pagi->id),
            ])->all();

            $schedule = WorkSchedule::create(['store_id' => $store->id, 'name' => 'Demo Jadwal Standar Senin-Sabtu', 'days' => $days, 'is_active' => true]);

            $userIds = collect($ctx->installers[$store->id] ?? [])->pluck('id')->all();
            if ($userIds) {
                EmployeeScheduleAssignment::assignBulk($schedule, $userIds, Carbon::today(), null);
            }
            $n++;
        }

        $this->info("{$n} toko diberi shift + jadwal kerja mingguan + penugasan installer.");
    }

    private function cleanup(): int
    {
        if (! $this->option('skip-others')) {
            foreach (['quotations:demo', 'booking-group:demo', 'inventaris-group:demo', 'karyawan-group:demo', 'marketing-group:demo', 'rewards-group:demo'] as $command) {
                $this->step("Bersihkan {$command}", fn () => $this->call($command, ['--cleanup' => true]));
            }
        }

        $state = File::exists(storage_path('app/' . self::STATE_FILE))
            ? json_decode(File::get(storage_path('app/' . self::STATE_FILE)), true)
            : null;

        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use ($state) {
                $bookingIds = Booking::withoutGlobalScopes()->where('booking_number', 'like', self::BOOKING_PREFIX . '%')->pluck('id');
                $spkIds = Spk::withoutGlobalScopes()->whereIn('booking_id', $bookingIds)->pluck('id');
                $invoiceIds = Invoice::withoutGlobalScopes()->where('notes', self::TAG)->pluck('id');
                $payableIds = Payable::withoutGlobalScopes()->where('notes', 'like', self::TAG . '%')->pluck('id');
                $receivableIds = Receivable::withoutGlobalScopes()->where('source_type', 'booking')->whereIn('source_id', $bookingIds)->pluck('id');
                $demoUserIds = User::where('email', 'like', '%' . self::EMAIL_SUFFIX)->pluck('id');
                $scheduleIds = WorkSchedule::withoutGlobalScopes()->where('name', 'like', 'Demo %')->pluck('id');

                // Jurnal hasil service (id di atas snapshot sebelum generate) — posted entries terkunci di level model,
                // jadi dihapus langsung lewat query builder (hanya server dev/demo).
                if ($state && isset($state['journal_start_id'])) {
                    $journalIds = DB::table('journal_entries')->where('id', '>', $state['journal_start_id'])->pluck('id');
                    DB::table('journal_entry_lines')->whereIn('journal_entry_id', $journalIds)->delete();
                    DB::table('journal_entries')->whereIn('id', $journalIds)->delete();
                }

                DB::table('booking_installers')->whereIn('booking_id', $bookingIds)->delete();
                DB::table('booking_down_payments')->whereIn('booking_id', $bookingIds)->delete();
                DB::table('refunds')->whereIn('booking_id', $bookingIds)->delete();
                DB::table('receivable_payments')->whereIn('receivable_id', $receivableIds)->delete();
                DB::table('receivables')->whereIn('id', $receivableIds)->delete();
                DB::table('spk_checklist_items')->whereIn('spk_id', $spkIds)->delete();
                DB::table('spk_damage_marks')->whereIn('spk_id', $spkIds)->delete();
                DB::table('spks')->whereIn('id', $spkIds)->delete();
                DB::table('bookings')->whereIn('id', $bookingIds)->delete();

                DB::table('invoice_items')->whereIn('invoice_id', $invoiceIds)->delete();
                DB::table('invoices')->whereIn('id', $invoiceIds)->delete();
                DB::table('payable_payments')->whereIn('payable_id', $payableIds)->delete();
                DB::table('payables')->whereIn('id', $payableIds)->delete();
                DB::table('suppliers')->where('notes', self::TAG)->delete();
                DB::table('finance_transactions')->where('description', 'like', self::TAG . '%')->delete();
                DB::table('recurring_bill_templates')->where('name', 'like', self::TAG . '%')->delete();
                DB::table('bank_statement_lines')->where('description', 'like', self::TAG . '%')->delete();
                DB::table('spend_promos')->where('name', 'like', self::TAG . '%')->delete();

                DB::table('employee_schedule_assignments')->whereIn('work_schedule_id', $scheduleIds)->delete();
                DB::table('work_schedules')->whereIn('id', $scheduleIds)->delete();
                DB::table('shifts')->where('name', 'like', 'Demo %')->delete();

                DB::table('technicians')->whereIn('user_id', $demoUserIds)->delete();
                DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $demoUserIds)->delete();
                DB::table('users')->whereIn('id', $demoUserIds)->delete();
                DB::table('customers')->where('email', 'like', '%' . self::EMAIL_SUFFIX)->delete();
                DB::table('customer_groups')->where('name', 'Demo Member Gold')->delete();
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        File::delete(storage_path('app/' . self::STATE_FILE));
        $this->info('Data demo presentasi dibersihkan.');

        return self::SUCCESS;
    }
}
