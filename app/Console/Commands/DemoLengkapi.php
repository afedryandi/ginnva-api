<?php

namespace App\Console\Commands;

use App\Models\AttendanceCorrectionRequest;
use App\Models\Attendance;
use App\Models\AttendanceDeductionRule;
use App\Models\Booking;
use App\Models\Budget;
use App\Models\CashCount;
use App\Models\CashTransfer;
use App\Models\ChartOfAccount;
use App\Models\CompanyPolicy;
use App\Models\ConsumableItem;
use App\Models\Customer;
use App\Models\EmployeeTraining;
use App\Models\FinanceCategory;
use App\Models\FinanceTransactionApprovalRequest;
use App\Models\GuideNote;
use App\Models\MarketingConsent;
use App\Models\NotificationCampaign;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\Partner;
use App\Models\PartnerNotification;
use App\Models\PaymentAccount;
use App\Models\PerformanceReview;
use App\Models\PolicyAcknowledgment;
use App\Models\PpeIssuance;
use App\Models\PpeType;
use App\Models\ProductImportLog;
use App\Models\RawMaterial;
use App\Models\SafetyIncident;
use App\Models\SalaryGrade;
use App\Models\ScheduledPriceChange;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\StockWriteOff;
use App\Models\Store;
use App\Models\TransactionApprovalRequest;
use App\Models\User;
use App\Services\CashCountService;
use App\Services\CashTransferService;
use App\Services\FinancialStatementService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Data demo untuk SEMUA menu panel yang masih kosong (daftar dari `php artisan data:cek-kosong`): Keuangan (rekening, anggaran, pemindahan kas,
 * hitung kas, dua jenis persetujuan), Karyawan (aturan potongan absensi, skala upah, peraturan, pelatihan, APD, K3, penilaian, koreksi absensi),
 * Inventaris (stok opname dan stok terbuang), Marketing (izin foto, dan perintah marketing-group:demo bila kosong), Notifikasi (templat, kampanye,
 * log pengiriman, riwayat partner), Produk (perubahan harga dan riwayat impor), dan Catatan Panduan.
 *
 * Semua baris ditandai: awalan "[DEMO]" pada nama/judul/catatan, email karyawan @lengkapi.ginnva.test, nomor booking DEMO-LGK-. Aman dijalankan
 * ulang (data demo lama dibersihkan dulu) dan bisa dihapus total dengan --cleanup. Hanya untuk server dev/test (DemoDataGuard).
 *
 * Tanpa efek samping nyata: semua permintaan HTTP keluar (push Expo) diblokir selama berjalan, booking dibuat tanpa event, Histori Aktivitas
 * dimatikan, dan pengajuan persetujuan dibuat langsung (tidak memanggil service yang mengirim notifikasi ke approver asli). Hanya satu jurnal
 * sungguhan yang dibuat: Pemindahan Kas lewat service aslinya (dibersihkan bersama barisnya). Kampanye notifikasi hanya berstatus terkirim, gagal,
 * atau dibatalkan (TIDAK ada yang terjadwal, supaya penjadwal tidak mengirimnya). Perubahan harga hanya berstatus berlaku atau dibatalkan.
 */
class DemoLengkapi extends Command
{
    use \App\Console\Commands\Concerns\DemoDataGuard;

    protected $signature = 'demo:lengkapi
        {--cleanup : Hapus semua data demo ini, bukan generate}';

    protected $description = 'Isi semua menu panel yang masih kosong dengan data demo (Keuangan, Karyawan, Inventaris, Notifikasi, Produk, dan lainnya)';

    private const TAG = '[DEMO]';
    private const EMAIL_SUFFIX = '@lengkapi.ginnva.test';
    private const BOOKING_PREFIX = 'DEMO-LGK-';
    private const CUSTOMER_NAME = 'Demo Pelanggan Lengkapi';

    /** @var list<array{string, string}> */
    private array $failures = [];

    /** @var array<string, int> */
    private array $made = [];

    private ?Store $store = null;
    private ?User $admin = null;
    /** @var array<string, User> */
    private array $staff = [];
    /** @var array<string, Booking> */
    private array $bookings = [];
    /** @var array<string, PaymentAccount> */
    private array $accounts = [];
    private ?Customer $customer = null;

    public function handle(): int
    {
        if ($this->refuseOutsideDemoEnvironment()) {
            return self::FAILURE;
        }

        // Tidak ada permintaan HTTP nyata (push Expo dan sejenisnya) selama perintah ini.
        Http::fake();
        $logging = method_exists(activity(), 'disableLogging');
        $logging && activity()->disableLogging();

        try {
            $this->cleanup();

            if ($this->option('cleanup')) {
                $this->call('marketing-group:demo', ['--cleanup' => true]);
                $this->info('Data demo Lengkapi dihapus.');

                return self::SUCCESS;
            }

            return $this->generate();
        } finally {
            $logging && activity()->enableLogging();
        }
    }

    private function generate(): int
    {
        $this->store = Store::where('is_active', true)->orderBy('id')->first();

        if (! $this->store) {
            $this->error('Belum ada toko aktif; buat toko dulu.');

            return self::FAILURE;
        }

        $this->admin = User::role(['super_admin', 'direksi'])->orderBy('id')->first();

        $this->step('Karyawan dan pelanggan demo', fn () => $this->people());
        $this->step('Booking demo', fn () => $this->demoBookings());
        $this->step('Rekening Kas & Bank', fn () => $this->paymentAccounts());
        $this->step('Anggaran', fn () => $this->budgets());
        $this->step('Pemindahan Kas', fn () => $this->cashTransfers());
        $this->step('Hitung Kas Harian', fn () => $this->cashCounts());
        $this->step('Persetujuan Pengeluaran', fn () => $this->expenseApprovals());
        $this->step('Persetujuan Transaksi', fn () => $this->transactionApprovals());
        $this->step('Aturan Potongan Absensi', fn () => $this->deductionRule());
        $this->step('Struktur & Skala Upah', fn () => $this->salaryGrades());
        $this->step('Peraturan & Kebijakan', fn () => $this->policies());
        $this->step('Pelatihan Karyawan', fn () => $this->trainings());
        $this->step('Pembagian APD', fn () => $this->ppe());
        $this->step('Kejadian K3', fn () => $this->incidents());
        $this->step('Penilaian Kinerja', fn () => $this->reviews());
        $this->step('Koreksi Absensi', fn () => $this->attendanceCorrections());
        $this->step('Stok Opname', fn () => $this->stockOpname());
        $this->step('Stok Terbuang', fn () => $this->writeOffs());
        $this->step('Izin Foto Pelanggan', fn () => $this->consents());
        $this->step('Templat dan Kampanye Notifikasi', fn () => $this->notificationTemplatesAndCampaigns());
        $this->step('Log Pengiriman', fn () => $this->deliveries());
        $this->step('Riwayat Notifikasi Partner', fn () => $this->partnerNotifications());
        $this->step('Perubahan Harga Terjadwal', fn () => $this->priceChanges());
        $this->step('Riwayat Impor Produk', fn () => $this->importLogs());
        $this->step('Catatan Panduan', fn () => $this->guideNotes());
        $this->step('Konten Marketing (marketing-group:demo)', fn () => $this->marketingContent());

        $this->newLine();
        $this->table(['Bagian', 'Baris dibuat'], collect($this->made)->map(fn (int $n, string $label) => [$label, $n])->values()->all());

        if ($this->failures) {
            $this->warn(count($this->failures) . ' bagian gagal dan dilewati (sisanya tetap dibuat):');
            foreach ($this->failures as [$label, $message]) {
                $this->line("  - {$label}: {$message}");
            }
        } else {
            $this->info('Semua bagian berhasil. Jalankan `php artisan data:cek-kosong` untuk memeriksa hasilnya.');
        }

        return self::SUCCESS;
    }

    private function step(string $label, \Closure $callback): void
    {
        try {
            DB::transaction($callback);
        } catch (Throwable $e) {
            $this->failures[] = [$label, class_basename($e) . ': ' . Str::limit($e->getMessage(), 200)];
        }
    }

    private function count(string $label, int $n): void
    {
        $this->made[$label] = $n;
    }

    // ------------------------------------------------------------------ orang dan booking

    private function people(): void
    {
        foreach (['store_manager', 'kasir', 'installer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        foreach ([
            'manager' => ['Demo Budi Manager', 'store_manager', 8500000],
            'kasir' => ['Demo Sari Kasir', 'kasir', 4200000],
            'installer' => ['Demo Tono Installer', 'installer', 4800000],
            'staf' => ['Demo Lina Staf', 'kasir', 4000000],
        ] as $key => [$name, $role, $salary]) {
            $user = User::create([
                'name' => $name,
                'email' => Str::slug($name) . self::EMAIL_SUFFIX,
                'phone' => '08' . random_int(11, 99) . random_int(1000000, 9999999),
                'password' => Str::random(24),
                'store_id' => $this->store->id,
                'join_date' => now()->subMonths(random_int(10, 36))->startOfMonth(),
                'base_salary' => $salary,
                'is_active' => true,
            ]);
            $user->syncRoles([$role]);
            $this->staff[$key] = $user;
        }

        $this->customer = Customer::create(['name' => self::CUSTOMER_NAME, 'phone_number' => '0899' . random_int(10000000, 99999999)]);
        $this->count('Karyawan demo', count($this->staff));
    }

    private function demoBookings(): void
    {
        foreach (['referral' => 'completed', 'rejected' => 'completed', 'dp' => 'confirmed', 'consent' => 'completed'] as $key => $status) {
            $this->bookings[$key] = Booking::withoutEvents(fn () => Booking::create([
                'booking_number' => self::BOOKING_PREFIX . strtoupper(substr($key, 0, 3)) . random_int(100, 999),
                'customer_id' => $this->customer->id,
                'customer_name' => $this->customer->name,
                'store_id' => $this->store->id,
                'service_type' => 'Pelindung Cat (PPF)',
                'product_ppf' => true,
                'preferred_date' => ($status === 'completed' ? now()->subDays(6) : now()->addDays(4))->toDateString(),
                'preferred_time' => '09:00',
                'status' => $status,
            ]));
        }

        $this->count('Booking demo', count($this->bookings));
    }

    // ------------------------------------------------------------------ Keuangan

    private function cashAccount(string $code, string $fallbackKind): ChartOfAccount
    {
        return ChartOfAccount::where('code', $code)->where('is_cash', true)->first()
            ?? ChartOfAccount::where('is_cash', true)->where('is_postable', true)->orderBy('code')->firstOrFail();
    }

    private function paymentAccounts(): void
    {
        $kas = $this->cashAccount('1101', 'kas');
        $bank = ChartOfAccount::where('code', '1102')->where('is_cash', true)->first() ?? $kas;

        $this->accounts['kas'] = PaymentAccount::create([
            'name' => self::TAG . ' Kas Toko', 'kind' => 'kas', 'chart_of_account_id' => $kas->id, 'store_id' => $this->store->id,
            'methods' => ['tunai'], 'is_default' => true, 'is_active' => true, 'notes' => 'Data demo.',
        ]);
        $this->accounts['bank'] = PaymentAccount::create([
            'name' => self::TAG . ' Rekening BCA Operasional', 'kind' => 'bank', 'chart_of_account_id' => $bank->id, 'store_id' => $this->store->id,
            'bank_name' => 'BCA', 'account_number' => '0123456789', 'account_holder' => 'PT Ginnva Indonesia',
            'methods' => ['transfer', 'edc'], 'is_default' => true, 'is_active' => true, 'notes' => 'Data demo.',
        ]);
        $this->accounts['petty'] = PaymentAccount::create([
            'name' => self::TAG . ' Kas Kecil Toko', 'kind' => 'kas_kecil', 'chart_of_account_id' => $kas->id, 'store_id' => $this->store->id,
            'methods' => [], 'is_default' => false, 'is_active' => true, 'notes' => 'Data demo.',
            'float_amount' => 1000000, 'voucher_limit' => 150000, 'custodian_id' => $this->staff['kasir']->id,
        ]);

        $this->count('Rekening Kas & Bank', 3);
    }

    private function budgets(): void
    {
        $accounts = ChartOfAccount::where('type', 'beban_operasional')->where('is_postable', true)->where('is_active', true)->orderBy('code')->limit(4)->get();
        $amounts = [12000000, 6500000, 4000000, 2500000];
        $made = 0;

        foreach ([now()->startOfMonth(), now()->subMonthNoOverflow()->startOfMonth()] as $month) {
            foreach ($accounts as $i => $account) {
                Budget::create([
                    'period_month' => $month->toDateString(), 'store_id' => null, 'chart_of_account_id' => $account->id,
                    'amount' => $amounts[$i], 'notes' => self::TAG . ' anggaran demo', 'created_by' => $this->admin?->id,
                ]);
                $made++;
            }
        }

        $this->count('Anggaran', $made);
    }

    private function cashTransfers(): void
    {
        $from = $this->accounts['kas']->chart_of_account_id;
        $to = $this->accounts['bank']->chart_of_account_id;

        if ($from === $to) {
            throw new \RuntimeException('Akun kas dan bank sama; Pemindahan Kas butuh dua akun berbeda.');
        }

        $service = app(CashTransferService::class);
        $service->create($from, $to, 2500000, now()->subDays(3), $this->store->id, self::TAG . ' Setor kas ke bank', $this->admin?->id);
        $service->create($to, $from, 750000, now()->subDay(), $this->store->id, self::TAG . ' Tarik tunai untuk kas kecil', $this->admin?->id);

        $this->count('Pemindahan Kas', 2);
    }

    private function cashCounts(): void
    {
        $service = app(CashCountService::class);
        $coa = $this->accounts['kas']->chartAccount;
        $system = app(FinancialStatementService::class)->balanceAsOf($coa, now()->subDay(), null);

        $service->record($this->accounts['kas'], now()->subDays(2), max(0, $system), self::TAG . ' cocok dengan saldo sistem', $this->staff['kasir']->id);
        $service->record($this->accounts['kas'], now()->subDay(), max(0, $system - 50000), self::TAG . ' selisih Rp50.000: uang kembalian belum dicatat', $this->staff['kasir']->id);

        $this->count('Hitung Kas Harian', 2);
    }

    private function expenseApprovals(): void
    {
        $category = FinanceCategory::where('type', 'out')->where('is_active', true)->where('is_group', false)->whereNotNull('chart_of_account_id')->orderBy('id')->first();

        if (! $category) {
            throw new \RuntimeException('Belum ada Kategori Keuangan pengeluaran yang terhubung ke akun.');
        }

        $payload = fn (float $amount, string $description) => [
            'type' => 'out', 'finance_category_id' => $category->id, 'payment_account_id' => $this->accounts['kas']->id,
            'store_id' => $this->store->id, 'amount' => $amount, 'transaction_date' => now()->toDateString(), 'description' => self::TAG . ' ' . $description,
        ];

        FinanceTransactionApprovalRequest::create(['payload' => $payload(1250000, 'Beli perlengkapan kebersihan bengkel'), 'status' => 'pending_manager', 'requested_by' => $this->staff['kasir']->id]);
        FinanceTransactionApprovalRequest::create([
            'payload' => $payload(7500000, 'Perpanjangan sewa ruko bulan depan'), 'status' => 'pending_direksi', 'requested_by' => $this->staff['kasir']->id,
            'manager_approved_by' => $this->staff['manager']->id, 'manager_approved_at' => now()->subHours(5),
        ]);
        FinanceTransactionApprovalRequest::create([
            'payload' => $payload(4200000, 'Pembelian kompresor tambahan'), 'status' => 'rejected', 'requested_by' => $this->staff['staf']->id,
            'rejected_by' => $this->admin?->id, 'rejected_at' => now()->subDay(), 'rejection_note' => self::TAG . ' ditolak: tunda sampai anggaran peralatan bulan depan',
        ]);

        $this->count('Persetujuan Pengeluaran', 3);
    }

    private function transactionApprovals(): void
    {
        TransactionApprovalRequest::create([
            'type' => 'booking_referral', 'booking_id' => $this->bookings['referral']->id, 'status' => 'pending', 'requested_by' => $this->staff['kasir']->id,
            'payload' => ['transaction_amount' => 4500000, 'amount_received' => 4500000, 'payment_method' => 'transfer'],
        ]);
        TransactionApprovalRequest::create([
            'type' => 'booking_referral', 'booking_id' => $this->bookings['rejected']->id, 'status' => 'rejected', 'requested_by' => $this->staff['staf']->id,
            'decided_by' => $this->admin?->id, 'decided_at' => now()->subDay(), 'decision_note' => self::TAG . ' nominal tidak sesuai nota, ajukan ulang',
            'payload' => ['transaction_amount' => 9800000, 'amount_received' => 9800000, 'payment_method' => 'tunai'],
        ]);
        TransactionApprovalRequest::create([
            'type' => 'booking_down_payment', 'booking_id' => $this->bookings['dp']->id, 'status' => 'pending', 'requested_by' => $this->staff['kasir']->id,
            'payload' => ['amount' => 1000000, 'notes' => self::TAG . ' DP awal'],
        ]);

        $this->count('Persetujuan Transaksi', 3);
    }

    // ------------------------------------------------------------------ Karyawan

    private function deductionRule(): void
    {
        AttendanceDeductionRule::create([
            'effective_from' => '2026-01-01', 'late_block_minutes' => 15, 'late_deduction_per_block' => 10000, 'alpha_fine_per_day' => 0,
            'notes' => self::TAG . ' aturan demo: potongan telat Rp10.000 per 15 menit, tanpa denda tetap Alpha', 'created_by' => $this->admin?->id,
        ]);

        $this->count('Aturan Potongan Absensi', 1);
    }

    private function salaryGrades(): void
    {
        foreach ([['Kasir', 3500000, 4500000], ['Installer Junior', 3800000, 5000000], ['Installer Senior', 5000000, 7500000], ['Store Manager', 7500000, 11000000]] as [$name, $min, $max]) {
            SalaryGrade::create(['name' => self::TAG . ' ' . $name, 'min_salary' => $min, 'max_salary' => $max, 'notes' => 'Data demo.', 'is_active' => true]);
        }

        $this->count('Struktur & Skala Upah', 4);
    }

    private function policies(): void
    {
        $general = CompanyPolicy::create([
            'title' => self::TAG . ' Peraturan Perusahaan 2026', 'category' => 'peraturan_perusahaan', 'version' => '1.0', 'effective_from' => '2026-01-01',
            'description' => 'Peraturan perusahaan demo: jam kerja, absensi, cuti, dan sanksi.', 'requires_acknowledgment' => true, 'is_published' => true, 'created_by' => $this->admin?->id,
        ]);
        CompanyPolicy::create([
            'title' => self::TAG . ' SOP Penanganan Bahan Kimia', 'category' => 'sop', 'version' => '1.2', 'effective_from' => '2026-03-01',
            'description' => 'SOP demo: penyimpanan, pemakaian, dan pembuangan bahan kimia bengkel.', 'requires_acknowledgment' => true, 'is_published' => true, 'created_by' => $this->admin?->id,
        ]);
        CompanyPolicy::create([
            'title' => self::TAG . ' Kebijakan Penggunaan Kendaraan Operasional', 'category' => 'kebijakan', 'version' => '0.9', 'effective_from' => null,
            'description' => 'Draf kebijakan demo, belum diterbitkan.', 'requires_acknowledgment' => false, 'is_published' => false, 'created_by' => $this->admin?->id,
        ]);

        foreach (['manager', 'kasir'] as $key) {
            PolicyAcknowledgment::create(['company_policy_id' => $general->id, 'user_id' => $this->staff[$key]->id, 'acknowledged_at' => now()->subDays(4), 'ip_address' => '10.0.0.' . random_int(2, 250)]);
        }

        $this->count('Peraturan & Kebijakan', 3);
    }

    private function trainings(): void
    {
        foreach ([
            ['manager', 'k3', 'Pelatihan Pemadam Api Ringan', 'Dinas Pemadam Kebakaran', 120, 245],
            ['kasir', 'produk', 'Pengetahuan Produk PPF dan Kaca Film', 'Ginnva Training Center', 60, null],
            ['installer', 'k3', 'K3 Dasar Bengkel', 'Lembaga K3 Nusantara', 380, -10],
            ['staf', 'k3', 'Penanganan Bahan Kimia', 'Lembaga K3 Nusantara', 90, 22],
        ] as [$key, $category, $title, $provider, $daysAgo, $validDays]) {
            EmployeeTraining::create([
                'user_id' => $this->staff[$key]->id, 'category' => $category, 'title' => self::TAG . ' ' . $title, 'provider' => $provider,
                'held_on' => now()->subDays($daysAgo)->toDateString(), 'valid_until' => $validDays === null ? null : now()->addDays($validDays)->toDateString(), 'notes' => 'Data demo.',
            ]);
        }

        $this->count('Pelatihan Karyawan', 4);
    }

    private function ppe(): void
    {
        $types = PpeType::where('is_active', true)->orderBy('id')->limit(3)->get();

        if ($types->isEmpty()) {
            throw new \RuntimeException('Belum ada Jenis APD; buat dulu di menu Jenis APD.');
        }

        $made = 0;

        foreach ([['installer', 0, 200, 20], ['installer', 1, 30, 150], ['staf', 0, 60, 120], ['manager', 2, 10, 170]] as [$key, $typeIndex, $issuedDaysAgo, $replaceInDays]) {
            $type = $types[$typeIndex % $types->count()];
            PpeIssuance::create([
                'user_id' => $this->staff[$key]->id, 'ppe_type_id' => $type->id, 'quantity' => 1,
                'issued_on' => now()->subDays($issuedDaysAgo)->toDateString(), 'replace_due_on' => now()->addDays($replaceInDays - $issuedDaysAgo)->toDateString(),
                'notes' => self::TAG . ' pembagian demo', 'issued_by' => $this->admin?->id,
            ]);
            $made++;
        }

        $this->count('Pembagian APD', $made);
    }

    private function incidents(): void
    {
        foreach ([
            ['near_miss', 'ringan', 'installer', 'open', 5, 'Nyaris tergelincir di lantai bengkel yang basah.', 'Lantai dikeringkan dan diberi tanda hati-hati.', 'Tidak ada pengering lantai di area pasang.', null],
            ['injury', 'sedang', 'staf', 'closed', 25, 'Jari terkena cutter saat memotong film.', 'P3K dan istirahat 1 hari.', 'Cutter tanpa pelindung mata pisau.', 'Semua cutter diganti tipe pengaman; briefing K3 ulang.'],
            ['property_damage', 'ringan', 'installer', 'open', 2, 'Spion kendaraan pelanggan tergores saat dipindahkan.', 'Dipoles ulang dan pelanggan diberi tahu.', 'Area parkir terlalu sempit.', null],
        ] as [$type, $severity, $key, $status, $daysAgo, $description, $action, $cause, $followUp]) {
            SafetyIncident::create([
                'store_id' => $this->store->id, 'occurred_at' => now()->subDays($daysAgo)->setTime(11, 30), 'type' => $type, 'severity' => $severity,
                'user_id' => $this->staff[$key]->id, 'description' => self::TAG . ' ' . $description, 'immediate_action' => $action, 'root_cause' => $cause,
                'follow_up' => $followUp, 'status' => $status, 'closed_at' => $status === 'closed' ? now()->subDays($daysAgo - 3) : null, 'reported_by' => $this->staff['manager']->id,
            ]);
        }

        $this->count('Kejadian K3', 3);
    }

    private function reviews(): void
    {
        foreach ([
            ['kasir', [4, 5, 4, 4], true, 'Teliti dan disiplin.', 'Perlu lebih proaktif menawarkan layanan tambahan.', 'Pegang laporan harian toko.'],
            ['installer', [3, 3, 4, 5], false, 'Inisiatif tinggi membantu rekan.', 'Kerapian tepi film masih perlu ditingkatkan.', 'Ikut sertifikasi PPF lanjutan.'],
            ['manager', [4, 4, 5, 4], true, 'Memimpin tim dengan baik.', 'Lebih rutin mengecek stok bahan.', 'Turunkan pekerjaan ulang di toko.'],
        ] as [$key, $scores, $acknowledged, $strengths, $improvements, $goals]) {
            PerformanceReview::create([
                'user_id' => $this->staff[$key]->id, 'period_label' => 'Semester 1 2026', 'reviewed_on' => now()->subDays(14)->toDateString(), 'reviewer_id' => $this->admin?->id,
                'score_quality' => $scores[0], 'score_discipline' => $scores[1], 'score_teamwork' => $scores[2], 'score_initiative' => $scores[3],
                'strengths' => self::TAG . ' ' . $strengths, 'improvements' => $improvements, 'goals' => $goals, 'acknowledged_at' => $acknowledged ? now()->subDays(10) : null,
            ]);
        }

        $this->count('Penilaian Kinerja', 3);
    }

    private function attendanceCorrections(): void
    {
        foreach ([['kasir', 6, 'pending', null], ['installer', 9, 'approved', 'Disetujui, bukti dari rekan kerja.'], ['staf', 12, 'rejected', 'Tidak ada bukti pendukung.']] as [$key, $daysAgo, $status, $notes]) {
            $user = $this->staff[$key];
            $date = now()->subDays($daysAgo)->toDateString();

            $attendance = Attendance::create(['user_id' => $user->id, 'store_id' => $this->store->id, 'date' => $date, 'entry_type' => 'alpha', 'notes' => self::TAG . ' lupa absen']);

            AttendanceCorrectionRequest::create([
                'attendance_id' => $attendance->id, 'user_id' => $user->id, 'store_id' => $this->store->id, 'date' => $date, 'entry_type' => 'clock',
                'clock_in_at' => $date . ' 08:40:00', 'clock_out_at' => $date . ' 17:05:00', 'reason' => self::TAG . ' lupa absen karena HP mati',
                'status' => $status, 'requested_by' => $user->id,
                'reviewed_by' => $status === 'pending' ? null : $this->admin?->id, 'reviewed_at' => $status === 'pending' ? null : now()->subDays($daysAgo - 1), 'review_notes' => $notes,
            ]);
        }

        $this->count('Koreksi Absensi', 3);
    }

    // ------------------------------------------------------------------ Inventaris

    private function stockOpname(): void
    {
        $items = collect(RawMaterial::orderBy('id')->limit(3)->get()->map(fn ($m) => ['raw_material', $m]))
            ->merge(ConsumableItem::orderBy('id')->limit(1)->get()->map(fn ($m) => ['consumable_item', $m]));

        if ($items->isEmpty()) {
            throw new \RuntimeException('Belum ada Bahan Baku atau Barang Habis Pakai untuk dihitung.');
        }

        $opname = StockOpname::create(['opname_number' => 'OPN-DEMO-' . random_int(1000, 9999), 'store_id' => $this->store->id, 'opname_date' => now()->subDays(2)->toDateString(), 'notes' => self::TAG . ' opname bulanan (demo, stok tidak diubah)', 'created_by' => $this->admin?->id]);

        foreach ($items->values() as $i => [$type, $item]) {
            $system = (float) $item->current_stock;
            $actual = max(0, $system + [0, -2, 1.5, -0.5][$i % 4]);
            StockOpnameItem::create([
                'stock_opname_id' => $opname->id, 'item_type' => $type, 'item_id' => $item->id, 'item_name' => $item->name, 'unit' => $item->unit,
                'system_quantity' => $system, 'actual_quantity' => $actual, 'delta' => round($actual - $system, 2),
            ]);
        }

        $this->count('Stok Opname', 1);
    }

    private function writeOffs(): void
    {
        $materials = RawMaterial::orderBy('id')->limit(3)->get();

        if ($materials->isEmpty()) {
            throw new \RuntimeException('Belum ada Bahan Baku untuk dicatat sebagai stok terbuang.');
        }

        foreach ($materials->values() as $i => $material) {
            $quantity = [1.5, 0.5, 2][$i % 3];
            $unitCost = (float) ($material->unit_cost ?: 50000);

            StockWriteOff::create([
                'write_off_number' => StockWriteOff::generateNumber(), 'writeoffable_type' => 'raw_material', 'writeoffable_id' => $material->id,
                'item_name' => $material->name, 'unit' => $material->unit, 'quantity' => $quantity, 'unit_cost' => $unitCost, 'total_value' => round($quantity * $unitCost, 2),
                'reason' => ['damaged', 'expired', 'lost'][$i % 3], 'note' => self::TAG . ' pencatatan demo (stok dan jurnal tidak diubah)', 'store_id' => $this->store->id, 'created_by' => $this->admin?->id,
            ]);
        }

        $this->count('Stok Terbuang', $materials->count());
    }

    // ------------------------------------------------------------------ Marketing

    private function consents(): void
    {
        $granted = MarketingConsent::create([
            'booking_id' => $this->bookings['consent']->id, 'customer_id' => $this->customer->id, 'scope' => MarketingConsent::SCOPE_JOB_PHOTOS,
            'granted_at' => now()->subDays(5), 'recorded_by_user_id' => $this->staff['manager']->id,
        ]);
        MarketingConsent::create([
            'booking_id' => $this->bookings['rejected']->id, 'customer_id' => $this->customer->id, 'scope' => MarketingConsent::SCOPE_JOB_PHOTOS,
            'granted_at' => now()->subDays(12), 'recorded_by_user_id' => $this->staff['manager']->id,
            'withdrawn_at' => now()->subDays(3), 'withdrawn_by_user_id' => $this->admin?->id, 'withdrawn_note' => self::TAG . ' pelanggan mencabut izin',
        ]);

        $this->count('Izin Foto Pelanggan', 2);
    }

    private function marketingContent(): void
    {
        if (\App\Models\Carousel::count() > 0) {
            $this->count('Konten Marketing (sudah ada, dilewati)', 0);

            return;
        }

        // Bersihkan sisa jalannya yang lalu dulu: pembuatan kategori Materi-nya gagal bila nama demo lama masih ada.
        $this->call('marketing-group:demo', ['--cleanup' => true]);
        $this->call('marketing-group:demo');
        $this->count('Konten Marketing (marketing-group:demo)', 1);
    }

    // ------------------------------------------------------------------ Notifikasi

    private function notificationTemplatesAndCampaigns(): void
    {
        foreach ([
            ['Promo Akhir Bulan', 'customer', 'promo', 'Promo akhir bulan Ginnva', 'Diskon pemasangan film untuk booking sampai akhir bulan. Booking sekarang di aplikasi.'],
            ['Pengingat Servis Berkala', 'customer', 'info', 'Waktunya servis berkala', 'Garansi Anda menyertakan pemeriksaan berkala. Jadwalkan kunjungan di aplikasi.'],
            ['Info Program Partner', 'partner', null, 'Program referral partner', 'Ajak pelanggan baru dan kumpulkan poin partner bulan ini.'],
        ] as [$name, $audience, $category, $title, $body]) {
            NotificationTemplate::create(['name' => self::TAG . ' ' . $name, 'audience' => $audience, 'category' => $category, 'title' => self::TAG . ' ' . $title, 'body' => $body, 'created_by' => $this->admin?->id]);
        }

        $sent = NotificationCampaign::create([
            'audience' => 'customer', 'category' => 'promo', 'title' => self::TAG . ' Promo Detailing Oktober', 'body' => 'Diskon detailing 15% untuk 50 pelanggan pertama.',
            'status' => NotificationCampaign::SENT, 'sent_at' => now()->subDays(3), 'recipients' => 120, 'skipped' => 12, 'devices_sent' => 98, 'devices_failed' => 4,
            'campaign_key' => (string) Str::uuid(), 'message' => self::TAG . ' kampanye contoh yang sudah terkirim', 'created_by' => $this->admin?->id,
        ]);
        NotificationCampaign::create([
            'audience' => 'customer', 'category' => 'info', 'title' => self::TAG . ' Info Perubahan Jam Operasional', 'body' => 'Jam operasional toko berubah mulai pekan depan.',
            'status' => NotificationCampaign::FAILED, 'sent_at' => now()->subDays(9), 'recipients' => 40, 'skipped' => 0, 'devices_sent' => 0, 'devices_failed' => 40,
            'campaign_key' => (string) Str::uuid(), 'message' => self::TAG . ' gagal: layanan push tidak dapat dihubungi', 'created_by' => $this->admin?->id,
        ]);
        NotificationCampaign::create([
            'audience' => 'partner', 'category' => null, 'title' => self::TAG . ' Pengumuman Partner (dibatalkan)', 'body' => 'Pengumuman program partner baru.',
            'status' => NotificationCampaign::CANCELLED, 'recipients' => 0, 'skipped' => 0, 'devices_sent' => 0, 'devices_failed' => 0,
            'campaign_key' => (string) Str::uuid(), 'message' => self::TAG . ' dibatalkan sebelum dikirim', 'created_by' => $this->admin?->id,
        ]);

        $this->count('Templat Notifikasi', 3);
        $this->count('Kampanye Notifikasi', 3);
        $this->sentCampaignId = $sent->id;
    }

    private ?int $sentCampaignId = null;

    private function deliveries(): void
    {
        $customerId = Customer::where('name', '!=', self::CUSTOMER_NAME)->value('id');
        $rows = [
            [NotificationDelivery::DELIVERED, null], [NotificationDelivery::DELIVERED, null], [NotificationDelivery::DELIVERED, null],
            [NotificationDelivery::FAILED, 'DeviceNotRegistered (demo)'], [NotificationDelivery::DEAD_TOKEN, 'Token tidak valid lagi (demo)'],
        ];

        foreach ($rows as $i => [$status, $error]) {
            NotificationDelivery::create([
                'customer_id' => $customerId, 'campaign_id' => $this->sentCampaignId, 'token' => 'ExponentPushToken[DEMO-' . Str::random(16) . ']',
                'title' => self::TAG . ' Promo Detailing Oktober', 'ticket_id' => null, 'status' => $status, 'error' => $error,
                'sent_at' => now()->subDays(3)->addMinutes($i), 'checked_at' => now()->subDays(3)->addMinutes($i + 5),
            ]);
        }

        $this->count('Log Pengiriman', count($rows));
    }

    private function partnerNotifications(): void
    {
        $partner = Partner::orderBy('id')->first();

        if (! $partner) {
            throw new \RuntimeException('Belum ada Partner untuk menerima notifikasi.');
        }

        PartnerNotification::withoutEvents(function () use ($partner) {
            PartnerNotification::create(['partner_id' => $partner->id, 'title' => self::TAG . ' Poin referral bertambah', 'body' => 'Referral Anda menghasilkan 50 poin (data demo).', 'data' => ['type' => 'demo'], 'read_at' => now()->subDays(2), 'sent_by' => $this->admin?->id]);
            PartnerNotification::create(['partner_id' => $partner->id, 'title' => self::TAG . ' Program partner baru', 'body' => 'Ada program bonus referral bulan ini (data demo).', 'data' => ['type' => 'demo'], 'read_at' => null, 'sent_by' => $this->admin?->id]);
        });

        $this->count('Riwayat Notifikasi Partner', 2);
    }

    // ------------------------------------------------------------------ Produk dan lainnya

    private function priceChanges(): void
    {
        ScheduledPriceChange::create([
            'name' => self::TAG . ' Penyesuaian harga September', 'effective_date' => now()->subDays(20)->toDateString(), 'percent' => 5, 'rounding_step' => 1000,
            'product_ids' => null, 'include_group_prices' => true, 'status' => ScheduledPriceChange::STATUS_APPLIED, 'applied_at' => now()->subDays(20), 'affected_prices' => 24,
            'notes' => 'Contoh perubahan yang sudah berlaku (tidak mengubah harga apa pun).', 'created_by' => $this->admin?->id,
        ]);
        ScheduledPriceChange::create([
            'name' => self::TAG . ' Kenaikan harga akhir tahun (dibatalkan)', 'effective_date' => now()->addDays(40)->toDateString(), 'percent' => 8, 'rounding_step' => 10000,
            'product_ids' => null, 'include_group_prices' => false, 'status' => ScheduledPriceChange::STATUS_CANCELLED, 'applied_at' => null, 'affected_prices' => null,
            'notes' => 'Contoh perubahan yang dibatalkan (tidak akan pernah diterapkan).', 'created_by' => $this->admin?->id,
        ]);

        $this->count('Perubahan Harga Terjadwal', 2);
    }

    private function importLogs(): void
    {
        ProductImportLog::create(['user_id' => $this->admin?->id, 'filename' => 'demo-harga-produk-oktober.xlsx', 'total_rows' => 58, 'updated_count' => 52, 'skipped_count' => 6, 'errors' => ['Baris 12: harga kosong', 'Baris 27: kode produk tidak dikenal', 'Baris 31: harga negatif']]);
        ProductImportLog::create(['user_id' => $this->admin?->id, 'filename' => 'demo-produk-baru.xlsx', 'total_rows' => 14, 'updated_count' => 14, 'skipped_count' => 0, 'errors' => []]);

        $this->count('Riwayat Impor Produk', 2);
    }

    private function guideNotes(): void
    {
        GuideNote::create(['guide_id' => 'booking', 'body' => self::TAG . ' Catatan perusahaan: konfirmasi booking maksimal 4 jam setelah masuk. Hubungi Store Manager bila ragu.', 'updated_by' => $this->admin?->id]);

        $this->count('Catatan Panduan', 1);
    }

    // ------------------------------------------------------------------ pembersihan

    private function cleanup(): void
    {
        $userIds = User::where('email', 'like', '%' . self::EMAIL_SUFFIX)->pluck('id')->all();
        $bookingIds = Booking::withoutGlobalScopes()->where('booking_number', 'like', self::BOOKING_PREFIX . '%')->pluck('id')->all();
        $like = self::TAG . '%';

        // Pemindahan Kas: jurnalnya ikut dihapus.
        $transfers = CashTransfer::withoutGlobalScopes()->where('notes', 'like', '%' . self::TAG . '%')->get();
        $journalIds = $transfers->pluck('journal_entry_id')->merge($transfers->pluck('void_journal_entry_id'))->filter()->all();
        CashTransfer::withoutGlobalScopes()->whereIn('id', $transfers->pluck('id'))->delete();
        if ($journalIds) {
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $journalIds)->delete();
            DB::table('journal_entries')->whereIn('id', $journalIds)->delete();
        }

        $accountIds = PaymentAccount::withoutGlobalScopes()->where('name', 'like', $like)->pluck('id')->all();
        CashCount::withoutGlobalScopes()->whereIn('payment_account_id', $accountIds)->delete();
        PaymentAccount::withoutGlobalScopes()->whereIn('id', $accountIds)->delete();
        Budget::withoutGlobalScopes()->where('notes', 'like', $like)->delete();

        FinanceTransactionApprovalRequest::withoutGlobalScopes()->whereIn('requested_by', $userIds)->delete();
        TransactionApprovalRequest::withoutGlobalScopes()->whereIn('booking_id', $bookingIds)->delete();
        MarketingConsent::withoutGlobalScopes()->whereIn('booking_id', $bookingIds)->delete();

        AttendanceCorrectionRequest::withoutGlobalScopes()->whereIn('user_id', $userIds)->delete();
        Attendance::withoutGlobalScopes()->whereIn('user_id', $userIds)->delete();
        EmployeeTraining::withoutGlobalScopes()->whereIn('user_id', $userIds)->delete();
        PpeIssuance::withoutGlobalScopes()->whereIn('user_id', $userIds)->delete();
        PerformanceReview::withoutGlobalScopes()->whereIn('user_id', $userIds)->delete();
        SafetyIncident::withoutGlobalScopes()->where('description', 'like', $like)->delete();

        $policyIds = CompanyPolicy::where('title', 'like', $like)->pluck('id')->all();
        PolicyAcknowledgment::whereIn('company_policy_id', $policyIds)->delete();
        CompanyPolicy::whereIn('id', $policyIds)->delete();
        AttendanceDeductionRule::where('notes', 'like', $like)->delete();
        SalaryGrade::where('name', 'like', $like)->delete();

        $opnameIds = StockOpname::withoutGlobalScopes()->where('notes', 'like', $like)->pluck('id')->all();
        StockOpnameItem::whereIn('stock_opname_id', $opnameIds)->delete();
        StockOpname::withoutGlobalScopes()->whereIn('id', $opnameIds)->delete();
        StockWriteOff::withoutGlobalScopes()->where('note', 'like', $like)->delete();

        NotificationDelivery::where('title', 'like', $like)->delete();
        NotificationCampaign::where('title', 'like', $like)->delete();
        NotificationTemplate::where('name', 'like', $like)->delete();
        PartnerNotification::where('title', 'like', $like)->delete();
        ScheduledPriceChange::where('name', 'like', $like)->delete();
        ProductImportLog::where('filename', 'like', 'demo-%')->delete();
        GuideNote::where('body', 'like', $like)->delete();

        DB::table((new Booking())->getTable())->whereIn('id', $bookingIds)->delete();
        DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
        DB::table((new User())->getTable())->whereIn('id', $userIds)->delete();
        DB::table((new Customer())->getTable())->where('name', self::CUSTOMER_NAME)->delete();
    }
}
