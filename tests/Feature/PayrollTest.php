<?php

namespace Tests\Feature;

use App\Filament\Resources\PayrollResource;
use App\Filament\Resources\PayrollResource\Pages\ListPayrolls;
use App\Models\Attendance;
use App\Models\Booking;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\Store;
use App\Models\Technician;
use App\Models\User;
use App\Services\PushNotificationService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Penggajian: perhitungan gaji bulanan (potongan telat dengan budget menit
 * kumulatif, potongan Alpha per hari kerja, proporsi karyawan baru, komisi
 * teknisi), generate massal, alur persetujuan spv_finance -> direksi,
 * posting otomatis ke Jurnal Umum yang selalu balance, hapus hanya draft, dan
 * slip gaji di aplikasi staf. Bulan uji: September 2026 (26 hari kerja, toko
 * tutup tiap Minggu).
 */
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'spv_finance', 'partner', 'store_manager'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->month = Carbon::parse('2026-09-01');
        $this->store = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true,
            'late_tolerance_minutes' => 15, 'late_deduction_amount' => 50000,
            'opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], 'open' => '09:00', 'close' => '18:00'], ['days' => ['sun'], 'closed' => true]],
        ]);
    }

    private function user(string $role, array $extra = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Karyawan ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id,
            'base_salary' => 2600000, 'join_date' => '2025-01-01',
        ], $extra));
        $user->assignRole($role);

        return $user;
    }

    private function attend(User $user, string $date, string $type = 'clock', int $late = 0): Attendance
    {
        return Attendance::create(['user_id' => $user->id, 'store_id' => $user->store_id, 'date' => $date, 'entry_type' => $type, 'late_minutes' => $late]);
    }

    private function payroll(User $user, array $overrides = []): Payroll
    {
        return Payroll::create(array_merge([
            'user_id' => $user->id, 'store_id' => $user->store_id, 'period_month' => '2026-09-01', 'base_salary' => 2600000,
            'working_days_in_month' => 26, 'prorated_base_salary' => 2600000, 'total_late_minutes' => 0, 'late_violation_days' => 0,
            'alpha_days' => 0, 'alpha_deduction' => 0, 'total_commission' => 0, 'has_unrated_commission' => false,
            'deduction_per_violation' => 0, 'total_deduction' => 0, 'net_pay' => 2600000, 'status' => 'draft',
        ], $overrides));
    }

    private function postedBookingFor(User $installer, string $entryDate, float $amount = 5000000, array $extra = []): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(1000000, 9999999)]);
        $entry = JournalEntry::create(['entry_number' => 'JE-T-' . uniqid(), 'entry_date' => $entryDate, 'description' => 'Penjualan', 'status' => 'posted']);
        $booking = Booking::create(array_merge([
            'booking_number' => 'BKG-T-' . uniqid(), 'customer_id' => $customer->id, 'store_id' => $this->store->id, 'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true, 'preferred_date' => $entryDate, 'status' => 'confirmed', 'transaction_amount' => $amount, 'journal_entry_id' => $entry->id,
        ], $extra));
        $booking->installers()->attach($installer->id);

        return $booking;
    }

    // ------------------------------------------------------------- perhitungan

    public function test_a_clean_month_pays_the_full_base_salary(): void
    {
        $payroll = Payroll::generateForMonth($this->user('kasir'), $this->month);

        $this->assertSame('draft', $payroll->status);
        $this->assertSame(26, $payroll->working_days_in_month, 'September 2026: 30 hari - 4 Minggu tutup.');
        $this->assertEquals(2600000, $payroll->net_pay);
        $this->assertEquals(0, $payroll->total_deduction);
        $this->assertSame('2026-09-01', $payroll->period_month->toDateString());
    }

    public function test_alpha_days_cost_one_daily_rate_each_while_approved_leave_costs_nothing(): void
    {
        $user = $this->user('kasir');
        $this->attend($user, '2026-09-02', 'alpha');
        $this->attend($user, '2026-09-03', 'alpha');
        $this->attend($user, '2026-09-04', 'leave');

        $payroll = Payroll::generateForMonth($user, $this->month);

        $this->assertSame(2, $payroll->alpha_days);
        $this->assertEquals(200000, $payroll->alpha_deduction, '2.600.000 / 26 hari = 100.000 per hari.');
        $this->assertEquals(2400000, $payroll->net_pay);
    }

    public function test_late_minutes_are_a_cumulative_monthly_budget_before_any_day_is_penalised(): void
    {
        $user = $this->user('kasir');
        foreach (['2026-09-02', '2026-09-03', '2026-09-04'] as $date) {
            $this->attend($user, $date, 'clock', 10);
        }

        $payroll = Payroll::generateForMonth($user, $this->month);

        $this->assertSame(30, $payroll->total_late_minutes);
        $this->assertSame(2, $payroll->late_violation_days, 'Kumulatif 10 -> aman, 20 -> lewat budget 15 (kena), 30 -> kena.');
        $this->assertEquals(100000, $payroll->total_deduction);
        $this->assertEquals(2500000, $payroll->net_pay);
    }

    public function test_lateness_within_the_budget_costs_nothing(): void
    {
        $user = $this->user('kasir');
        $this->attend($user, '2026-09-02', 'clock', 15);

        $payroll = Payroll::generateForMonth($user, $this->month);

        $this->assertSame(0, $payroll->late_violation_days);
        $this->assertEquals(2600000, $payroll->net_pay);
    }

    public function test_attendance_outside_the_month_is_ignored(): void
    {
        $user = $this->user('kasir');
        $this->attend($user, '2026-08-31', 'alpha');
        $this->attend($user, '2026-10-01', 'alpha');

        $this->assertSame(0, Payroll::generateForMonth($user, $this->month)->alpha_days);
    }

    public function test_a_new_joiner_is_paid_only_for_the_working_days_since_joining(): void
    {
        $user = $this->user('kasir', ['join_date' => '2026-09-21']);

        $payroll = Payroll::generateForMonth($user, $this->month);

        $this->assertEquals(2600000, $payroll->base_salary);
        $this->assertEquals(900000, $payroll->prorated_base_salary, '9 hari kerja (21-30 Sep tanpa Minggu 27) x 100.000.');
        $this->assertEquals(900000, $payroll->net_pay);
    }

    public function test_a_joiner_of_an_earlier_month_gets_the_full_salary(): void
    {
        $payroll = Payroll::generateForMonth($this->user('kasir', ['join_date' => '2026-08-31']), $this->month);

        $this->assertEquals(2600000, $payroll->prorated_base_salary);
    }

    public function test_regenerating_a_draft_updates_the_same_row(): void
    {
        $user = $this->user('kasir');
        $first = Payroll::generateForMonth($user, $this->month);
        $this->attend($user, '2026-09-02', 'alpha');

        $second = Payroll::generateForMonth($user, $this->month);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payroll::where('user_id', $user->id)->count());
        $this->assertEquals(2500000, $second->net_pay);
    }

    public function test_paid_and_pending_rows_cannot_be_regenerated_and_users_without_a_store_are_rejected(): void
    {
        $paid = $this->user('kasir');
        $pending = $this->user('kasir');
        $this->payroll($paid, ['status' => 'paid']);
        $this->payroll($pending, ['status' => 'pending_approval']);
        $storeless = $this->user('kasir', ['store_id' => null]);

        foreach ([$paid, $pending, $storeless] as $blocked) {
            try {
                Payroll::generateForMonth($blocked, $this->month);
                $this->fail("Seharusnya ditolak: {$blocked->name}");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        $this->assertEquals(2600000, Payroll::where('user_id', $paid->id)->value('net_pay'));
    }

    public function test_technician_commission_is_added_for_jobs_booked_in_the_period_only(): void
    {
        $installer = $this->user('kasir');
        Technician::create(['store_id' => $this->store->id, 'user_id' => $installer->id, 'name' => 'Teknisi', 'level' => 'intermediate', 'status' => 'active', 'commission_amount' => 150000]);
        $this->postedBookingFor($installer, '2026-09-10');
        $this->postedBookingFor($installer, '2026-09-20');
        $this->postedBookingFor($installer, '2026-08-25');
        $this->postedBookingFor($installer, '2026-09-15', 0);

        $payroll = Payroll::generateForMonth($installer, $this->month);

        $this->assertEquals(300000, $payroll->total_commission, 'Hanya 2 job di periode dengan nominal transaksi > 0.');
        $this->assertEquals(2900000, $payroll->net_pay);
        $this->assertFalse($payroll->has_unrated_commission);
    }

    public function test_jobs_with_an_unset_commission_rate_are_flagged_not_counted_as_zero(): void
    {
        $installer = $this->user('kasir');
        Technician::create(['store_id' => $this->store->id, 'user_id' => $installer->id, 'name' => 'Teknisi', 'level' => 'intermediate', 'status' => 'active']);
        $this->postedBookingFor($installer, '2026-09-10');

        $payroll = Payroll::generateForMonth($installer, $this->month);

        $this->assertEquals(0, $payroll->total_commission);
        $this->assertTrue($payroll->has_unrated_commission);
    }

    public function test_an_accountant_without_a_store_still_gets_deductions_and_commission_counted(): void
    {
        $spv = $this->user('spv_finance', ['store_id' => null]);
        $employee = $this->user('kasir');
        $this->attend($employee, '2026-09-02', 'alpha');
        $installer = $this->user('kasir');
        Technician::create(['store_id' => $this->store->id, 'user_id' => $installer->id, 'name' => 'Teknisi', 'level' => 'intermediate', 'status' => 'active', 'commission_amount' => 150000]);
        $this->postedBookingFor($installer, '2026-09-10');

        $this->actingAs($spv, 'web');
        $withDeduction = Payroll::generateForMonth($employee, $this->month);
        $withCommission = Payroll::generateForMonth($installer, $this->month);

        $this->assertSame(1, $withDeduction->alpha_days, 'Akun tanpa toko tidak boleh membuat potongan Alpha hilang diam-diam.');
        $this->assertEquals(2500000, $withDeduction->net_pay);
        $this->assertEquals(150000, $withCommission->total_commission);
    }

    // ------------------------------------------------------------- akses & generate massal

    public function test_only_full_access_and_menu_enabled_finance_supervisors_can_open_payroll(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(PayrollResource::canViewAny());

        $this->actingAs($this->user('spv_finance', ['store_id' => null]), 'web');
        $this->assertTrue(PayrollResource::canViewAny());

        $this->actingAs($this->user('spv_finance', ['store_id' => null, 'menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse((bool) PayrollResource::canViewAny());

        foreach (['store_manager', 'kasir'] as $role) {
            $this->actingAs($this->user($role), 'web');
            $this->assertFalse((bool) PayrollResource::canViewAny(), "{$role} tidak boleh melihat gaji.");
        }

        $this->assertFalse(PayrollResource::canCreate());
    }

    public function test_bulk_generate_covers_eligible_employees_and_reports_the_rest(): void
    {
        $this->actingAs($this->user('super_admin', ['base_salary' => null, 'store_id' => null]), 'web');
        $ok1 = $this->user('kasir');
        $ok2 = $this->user('kasir');
        $noSalary = $this->user('kasir', ['base_salary' => null]);
        $inactive = $this->user('kasir', ['is_active' => false]);
        $partner = $this->user('partner');
        $alreadyPaid = $this->user('kasir');
        $this->payroll($alreadyPaid, ['status' => 'paid']);

        Livewire::test(ListPayrolls::class)
            ->callTableAction('generate', data: ['month' => '2026-09-01', 'store_id' => $this->store->id])
            ->assertHasNoTableActionErrors();

        foreach ([$ok1, $ok2] as $employee) {
            $this->assertSame(1, Payroll::where('user_id', $employee->id)->count());
        }
        foreach ([$noSalary, $inactive, $partner] as $skipped) {
            $this->assertSame(0, Payroll::where('user_id', $skipped->id)->count(), "{$skipped->name} dilewati.");
        }
        $this->assertSame('paid', Payroll::where('user_id', $alreadyPaid->id)->value('status'));
    }

    public function test_bulk_generate_by_a_finance_supervisor_applies_deductions(): void
    {
        $spv = $this->user('spv_finance', ['store_id' => null]);
        $employee = $this->user('kasir');
        $this->attend($employee, '2026-09-02', 'alpha');
        $this->actingAs($spv, 'web');

        Livewire::test(ListPayrolls::class)
            ->callTableAction('generate', data: ['month' => '2026-09-01', 'store_id' => $this->store->id])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(2500000, Payroll::where('user_id', $employee->id)->value('net_pay'));
    }

    // ------------------------------------------------------------- persetujuan & pembayaran

    public function test_a_finance_supervisor_requests_payment_and_only_full_access_can_approve(): void
    {
        $spv = $this->user('spv_finance', ['store_id' => null]);
        $admin = $this->user('direksi', ['store_id' => null]);
        $payroll = $this->payroll($this->user('kasir'));

        $this->actingAs($spv, 'web');
        Livewire::test(ListPayrolls::class)
            ->assertTableActionVisible('requestPayment', $payroll)
            ->assertTableActionHidden('markPaid', $payroll)
            ->assertTableActionHidden('approvePayment', $payroll)
            ->callTableAction('requestPayment', $payroll);

        $fresh = $payroll->fresh();
        $this->assertSame('pending_approval', $fresh->status);
        $this->assertSame($spv->id, $fresh->payment_requested_by);
        $this->assertSame(1, $admin->notifications()->count(), 'Direksi diberi tahu ada pengajuan.');

        Livewire::test(ListPayrolls::class)
            ->assertTableActionHidden('requestPayment', $fresh)
            ->assertTableActionHidden('approvePayment', $fresh)
            ->assertTableActionHidden('rejectPayment', $fresh);
    }

    public function test_full_access_sees_direct_payment_not_the_request_button(): void
    {
        $payroll = $this->payroll($this->user('kasir'));
        $this->actingAs($this->user('super_admin', ['store_id' => null]), 'web');

        Livewire::test(ListPayrolls::class)
            ->assertTableActionHidden('requestPayment', $payroll)
            ->assertTableActionVisible('markPaid', $payroll);
    }

    public function test_approving_a_request_pays_it_posts_a_balanced_journal_and_notifies_the_employee(): void
    {
        $employee = $this->user('kasir');
        $spv = $this->user('spv_finance', ['store_id' => null]);
        $admin = $this->user('direksi', ['store_id' => null]);
        $payroll = $this->payroll($employee, [
            'status' => 'pending_approval', 'payment_requested_by' => $spv->id, 'payment_requested_at' => now(),
            'alpha_days' => 1, 'alpha_deduction' => 100000, 'total_deduction' => 100000, 'net_pay' => 2500000,
        ]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title) => $ids === [$employee->id] && $title === 'Gaji Sudah Dibayar'));
        $this->actingAs($admin, 'web');

        Livewire::test(ListPayrolls::class)->callTableAction('approvePayment', $payroll);

        $fresh = $payroll->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame($admin->id, $fresh->paid_by);
        $this->assertNotNull($fresh->journal_entry_id);

        $entry = JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($fresh->journal_entry_id);
        $this->assertSame('posted', $entry->status);
        $this->assertEquals($entry->lines->sum('debit'), $entry->lines->sum('credit'), 'Jurnal harus balance.');
        $this->assertEquals(2600000, $entry->lines->sum('debit'));
        $byCode = fn (string $code) => $entry->lines->firstWhere('chart_of_account_id', ChartOfAccount::where('code', $code)->value('id'));
        $this->assertEquals(2600000, $byCode('6110')->debit);
        $this->assertEquals(100000, $byCode('6120')->credit);
        $this->assertEquals(2500000, $byCode('1101')->credit);
    }

    public function test_commission_is_posted_to_its_own_account(): void
    {
        $employee = $this->user('kasir');
        $payroll = $this->payroll($employee, ['total_commission' => 300000, 'net_pay' => 2900000]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());
        $this->actingAs($this->user('super_admin', ['store_id' => null]), 'web');

        Livewire::test(ListPayrolls::class)->callTableAction('markPaid', $payroll);

        $entry = JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($payroll->fresh()->journal_entry_id);
        $this->assertEquals($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertEquals(300000, $entry->lines->firstWhere('chart_of_account_id', ChartOfAccount::where('code', '5300')->value('id'))->debit);
    }

    public function test_a_payroll_whose_deductions_exceed_the_salary_still_posts_a_balanced_journal(): void
    {
        $employee = $this->user('kasir', ['base_salary' => 100000]);
        $payroll = $this->payroll($employee, [
            'base_salary' => 100000, 'prorated_base_salary' => 100000, 'late_violation_days' => 4, 'deduction_per_violation' => 50000,
            'total_deduction' => 200000, 'net_pay' => 0,
        ]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());
        $this->actingAs($this->user('super_admin', ['store_id' => null]), 'web');

        Livewire::test(ListPayrolls::class)->callTableAction('markPaid', $payroll);

        $fresh = $payroll->fresh();
        $this->assertSame('paid', $fresh->status, 'Jurnal tidak balance tidak boleh menggagalkan pembayaran.');
        $entry = JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($fresh->journal_entry_id);
        $this->assertEquals($entry->lines->sum('debit'), $entry->lines->sum('credit'));
    }

    public function test_a_failed_posting_leaves_the_payroll_unpaid(): void
    {
        ChartOfAccount::where('code', '1101')->update(['is_active' => false]);
        $payroll = $this->payroll($this->user('kasir'));
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldNotReceive('sendToUsers'));
        $this->actingAs($this->user('super_admin', ['store_id' => null]), 'web');

        Livewire::test(ListPayrolls::class)->callTableAction('markPaid', $payroll);

        $fresh = $payroll->fresh();
        $this->assertSame('draft', $fresh->status, 'Tidak ada gaji yang ditandai dibayar tanpa jurnal.');
        $this->assertNull($fresh->journal_entry_id);
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('reference_type', 'payroll')->count());
    }

    public function test_a_paid_payroll_cannot_be_paid_again(): void
    {
        $payroll = $this->payroll($this->user('kasir'));
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());
        $this->actingAs($this->user('super_admin', ['store_id' => null]), 'web');

        Livewire::test(ListPayrolls::class)->callTableAction('markPaid', $payroll);
        $paid = $payroll->fresh();
        $entryId = $paid->journal_entry_id;
        $this->assertSame('paid', $paid->status);

        try {
            app(\App\Services\PayrollPostingService::class)->post($paid);
            $this->fail('Posting kedua seharusnya ditolak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sudah pernah diposting', $e->getMessage());
        }

        $this->assertSame($entryId, $payroll->fresh()->journal_entry_id);
        $this->assertSame(1, JournalEntry::withoutGlobalScopes()->where('reference_type', 'payroll')->where('reference_id', $payroll->id)->count());
    }
    public function test_rejecting_a_request_returns_it_to_draft_with_a_reason_for_the_requester(): void
    {
        $spv = $this->user('spv_finance', ['store_id' => null]);
        $payroll = $this->payroll($this->user('kasir'), ['status' => 'pending_approval', 'payment_requested_by' => $spv->id, 'payment_requested_at' => now()]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title, string $body) => $ids === [$spv->id]
            && $title === 'Pengajuan Pembayaran Gaji Ditolak' && str_contains($body, 'Angka belum final')));
        $this->actingAs($this->user('direksi', ['store_id' => null]), 'web');

        Livewire::test(ListPayrolls::class)
            ->callTableAction('rejectPayment', $payroll, data: ['note' => ''])
            ->assertHasTableActionErrors(['note' => 'required']);
        Livewire::test(ListPayrolls::class)->callTableAction('rejectPayment', $payroll, data: ['note' => 'Angka belum final']);

        $fresh = $payroll->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->payment_requested_by);
    }

    // ------------------------------------------------------------- hapus & slip

    public function test_only_draft_rows_can_be_deleted_and_a_finance_supervisor_needs_an_explicit_grant(): void
    {
        $draft = $this->payroll($this->user('kasir'));
        $paid = $this->payroll($this->user('kasir'), ['status' => 'paid']);

        $this->actingAs($this->user('spv_finance', ['store_id' => null]), 'web');
        $this->assertFalse((bool) PayrollResource::canDelete($draft));

        $granted = $this->user('spv_finance', ['store_id' => null, 'menu_permissions' => [PayrollResource::class => ['delete']]]);
        $this->actingAs($granted, 'web');
        $this->assertTrue((bool) PayrollResource::canDelete($draft));
        $this->assertFalse((bool) PayrollResource::canDelete($paid));

        $this->actingAs($this->user('super_admin', ['store_id' => null]), 'web');
        Livewire::test(ListPayrolls::class)
            ->assertTableActionHidden('delete', $paid)
            ->callTableAction('delete', $draft);

        $this->assertNull(Payroll::find($draft->id));
        $this->assertNotNull(Payroll::find($paid->id));
    }

    public function test_the_payslip_pdf_can_be_downloaded_with_a_dated_filename(): void
    {
        $employee = $this->user('kasir', ['name' => 'Siti Aminah']);
        $payroll = $this->payroll($employee);
        $this->actingAs($this->user('super_admin', ['store_id' => null]), 'web');

        Livewire::test(ListPayrolls::class)
            ->callTableAction('downloadPayslip', $payroll)
            ->assertFileDownloaded('Slip-Gaji-Siti-Aminah-202609.pdf');
    }

    // ------------------------------------------------------------- aplikasi staf

    public function test_the_staff_app_lists_only_my_paid_payrolls_newest_first(): void
    {
        $me = $this->user('kasir');
        $other = $this->user('kasir');
        $this->payroll($me, ['period_month' => '2026-07-01', 'status' => 'paid', 'paid_at' => now()]);
        $this->payroll($me, ['period_month' => '2026-08-01', 'status' => 'paid', 'paid_at' => now(), 'total_commission' => 150000, 'net_pay' => 2750000]);
        $this->payroll($me, ['period_month' => '2026-09-01', 'status' => 'draft']);
        $this->payroll($other, ['status' => 'paid', 'paid_at' => now()]);

        $this->getJson('/api/staff/payroll')->assertStatus(401);
        $rows = $this->actingAs($me, 'api')->getJson('/api/staff/payroll')->assertSuccessful()->json('payrolls');

        $this->assertSame(['2026-08-01', '2026-07-01'], collect($rows)->pluck('period_month')->all(), 'Draft dan milik orang lain tidak tampil.');
        $this->assertEquals(150000, $rows[0]['total_commission']);
        $this->assertEquals(2750000, $rows[0]['net_pay']);
        $this->assertArrayHasKey('has_unrated_commission', $rows[0]);
    }

    public function test_the_payslip_download_is_only_for_my_own_paid_payroll(): void
    {
        $me = $this->user('kasir');
        $other = $this->user('kasir');
        $mine = $this->payroll($me, ['status' => 'paid', 'paid_at' => now()]);
        $draft = $this->payroll($me, ['period_month' => '2026-08-01']);
        $theirs = $this->payroll($other, ['status' => 'paid', 'paid_at' => now()]);

        $ok = $this->actingAs($me, 'api')->get("/api/staff/payroll/{$mine->id}/slip");
        $ok->assertSuccessful();
        $this->assertSame('application/pdf', $ok->headers->get('Content-Type'));

        $this->actingAs($me, 'api')->get("/api/staff/payroll/{$draft->id}/slip")->assertStatus(404);
        $this->actingAs($me, 'api')->get("/api/staff/payroll/{$theirs->id}/slip")->assertStatus(404);
    }
}
