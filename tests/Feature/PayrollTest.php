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
        // Aturan potongan absensi (diisi staf di panel): blok telat 15 menit x Rp50.000, tanpa denda Alpha.
        \App\Models\AttendanceDeductionRule::create(['effective_from' => '2026-01-01', 'late_block_minutes' => 15, 'late_deduction_per_block' => 50000, 'alpha_fine_per_day' => 0]);
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

    public function test_late_minutes_are_summed_for_the_month_and_charged_per_full_block(): void
    {
        $user = $this->user('kasir');
        foreach (['2026-09-02', '2026-09-03', '2026-09-04'] as $date) {
            $this->attend($user, $date, 'clock', 10);
        }

        $payroll = Payroll::generateForMonth($user, $this->month);

        $this->assertSame(30, $payroll->total_late_minutes);
        $this->assertSame(2, $payroll->late_blocks, 'Total 30 menit = 2 blok penuh (15 menit) -> 2 x Rp50.000.');
        $this->assertEquals(100000, $payroll->total_deduction);
        $this->assertEquals(2500000, $payroll->net_pay);
    }

    public function test_lateness_below_one_full_block_costs_nothing(): void
    {
        $user = $this->user('kasir');
        $this->attend($user, '2026-09-02', 'clock', 14);

        $payroll = Payroll::generateForMonth($user, $this->month);

        $this->assertSame(0, $payroll->late_blocks);
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
        Technician::create(['store_id' => $this->store->id, 'user_id' => $installer->id, 'name' => 'Installer', 'level' => 'intermediate', 'status' => 'active', 'commission_amount' => 150000]);
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
        Technician::create(['store_id' => $this->store->id, 'user_id' => $installer->id, 'name' => 'Installer', 'level' => 'intermediate', 'status' => 'active']);
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
        Technician::create(['store_id' => $this->store->id, 'user_id' => $installer->id, 'name' => 'Installer', 'level' => 'intermediate', 'status' => 'active', 'commission_amount' => 150000]);
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
        $this->mock(PushNotificationService::class, function ($mock) use ($employee, $spv) {
            $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title) => $ids === [$employee->id] && $title === 'Gaji Sudah Dibayar');
            $mock->shouldReceive('sendToUsers')->once()->withArgs(fn (array $ids, string $title) => $ids === [$spv->id] && $title === 'Pengajuan Pembayaran Gaji Disetujui');
        });
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

    // ------------------------------------------------------------- aksi massal & potongan melebihi gaji

    public function test_a_finance_supervisor_requests_many_slips_at_once_with_one_summary_notification(): void
    {
        $spv = $this->user('spv_finance', ['store_id' => null]);
        $admin = $this->user('direksi', ['store_id' => null]);
        $a = $this->payroll($this->user('kasir'));
        $b = $this->payroll($this->user('kasir'));
        $already = $this->payroll($this->user('kasir'), ['status' => 'paid']);

        $this->actingAs($spv, 'web');
        Livewire::test(ListPayrolls::class)
            ->assertTableBulkActionVisible('bulkRequestPayment')
            ->assertTableBulkActionHidden('bulkApprove')
            ->assertTableBulkActionHidden('bulkMarkPaid')
            ->callTableBulkAction('bulkRequestPayment', [$a, $b, $already]);

        $this->assertSame('pending_approval', $a->fresh()->status);
        $this->assertSame('pending_approval', $b->fresh()->status);
        $this->assertSame('paid', $already->fresh()->status, 'Yang sudah dibayar dilewati.');
        $this->assertSame($spv->id, $a->fresh()->payment_requested_by);
        $this->assertSame(1, $admin->notifications()->count(), 'Satu pemberitahuan ringkasan, bukan satu per slip.');
    }

    public function test_full_access_approves_and_pays_many_slips_and_each_employee_is_told(): void
    {
        $admin = $this->user('direksi', ['store_id' => null]);
        $e1 = $this->user('kasir');
        $e2 = $this->user('kasir');
        $pending1 = $this->payroll($e1, ['status' => 'pending_approval']);
        $pending2 = $this->payroll($e2, ['status' => 'pending_approval', 'net_pay' => 2000000]);
        $draft = $this->payroll($this->user('kasir'));
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->twice());
        $this->actingAs($admin, 'web');

        Livewire::test(ListPayrolls::class)
            ->assertTableBulkActionVisible('bulkApprove')
            ->assertTableBulkActionVisible('bulkMarkPaid')
            ->assertTableBulkActionHidden('bulkRequestPayment')
            ->callTableBulkAction('bulkApprove', [$pending1, $pending2, $draft]);

        $this->assertSame('paid', $pending1->fresh()->status);
        $this->assertSame('paid', $pending2->fresh()->status);
        $this->assertSame($admin->id, $pending1->fresh()->paid_by);
        $this->assertSame('draft', $draft->fresh()->status, 'Draft tidak ikut disetujui oleh aksi persetujuan.');
        $this->assertNotNull($pending1->fresh()->journal_entry_id);
    }

    public function test_mark_paid_in_bulk_only_takes_drafts_and_the_server_refuses_a_non_full_access_caller(): void
    {
        $draft = $this->payroll($this->user('kasir'));
        $pending = $this->payroll($this->user('kasir'), ['status' => 'pending_approval']);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->once());
        $this->actingAs($this->user('direksi', ['store_id' => null]), 'web');

        Livewire::test(ListPayrolls::class)->callTableBulkAction('bulkMarkPaid', [$draft, $pending]);

        $this->assertSame('paid', $draft->fresh()->status);
        $this->assertSame('pending_approval', $pending->fresh()->status);

        $method = new \ReflectionMethod(PayrollResource::class, 'payOne');
        $method->setAccessible(true);
        $this->actingAs($this->user('spv_finance', ['store_id' => null]), 'web');
        $this->expectException(\RuntimeException::class);
        $method->invoke(null, $pending->id, 'pending_approval');
    }

    public function test_deductions_larger_than_the_pay_are_flagged_instead_of_silently_disappearing(): void
    {
        $over = $this->payroll($this->user('kasir'), [
            'prorated_base_salary' => 1000000, 'total_commission' => 0, 'total_additions' => 0, 'total_deduction' => 200000,
            'total_item_deductions' => 1500000, 'net_pay' => 0,
        ]);
        $normal = $this->payroll($this->user('kasir'), ['total_deduction' => 100000, 'net_pay' => 2500000]);

        $this->assertEquals(700000, $over->uncollected_deduction);
        $this->assertEquals(0, $normal->uncollected_deduction);

        $this->actingAs($this->user('direksi', ['store_id' => null]), 'web');
        Livewire::test(ListPayrolls::class)
            ->assertSee('tidak terpotong (melebihi gaji)')
            ->assertTableColumnStateSet('net_pay', 0, $over);

        $me = $this->user('kasir');
        $paid = $this->payroll($me, ['status' => 'paid', 'prorated_base_salary' => 1000000, 'total_deduction' => 1200000, 'net_pay' => 0, 'paid_at' => now()]);
        $this->actingAs($me, 'api')->getJson('/api/staff/payroll')->assertSuccessful()
            ->assertJsonPath('payrolls.0.uncollected_deduction', 200000);
    }

    public function test_the_period_filter_narrows_the_list(): void
    {
        $this->actingAs($this->user('direksi', ['store_id' => null]), 'web');
        $last = now()->subMonth()->startOfMonth()->toDateString();
        $september = $this->payroll($this->user('kasir'), ['period_month' => $last]);
        $august = $this->payroll($this->user('kasir'), ['period_month' => now()->subMonths(2)->startOfMonth()->toDateString()]);

        Livewire::test(ListPayrolls::class)
            ->filterTable('period_month', $last)
            ->assertCanSeeTableRecords([$september])->assertCanNotSeeTableRecords([$august]);
    }

    // ------------------------------------------------------------- slip usang & batalkan dibayar

    private function isStale(Payroll $payroll): bool
    {
        return (bool) PayrollResource::getEloquentQuery()->findOrFail($payroll->id)->isStale();
    }

    public function test_a_draft_goes_stale_when_attendance_or_base_salary_changes_and_recalculating_clears_it(): void
    {
        $employee = $this->user('kasir');
        $payroll = $this->payroll($employee);
        \Illuminate\Support\Facades\DB::table('payrolls')->where('id', $payroll->id)->update(['updated_at' => now()->subDay()]);
        \Illuminate\Support\Facades\DB::table('attendance_deduction_rules')->update(['updated_at' => now()->subDays(2)]);
        $payroll = $payroll->fresh();
        $this->assertFalse($this->isStale($payroll), 'Baru dihitung, belum ada yang berubah.');

        $this->attend($employee, '2026-09-10', 'clock', 20);
        $this->assertTrue($this->isStale($payroll), 'Absensi di bulan slip berubah sesudah slip dihitung.');

        $this->actingAs($this->user('direksi', ['store_id' => null]), 'web');
        Livewire::test(ListPayrolls::class)->assertSee('hitung ulang')->callTableAction('recalculate', $payroll->fresh());

        $this->assertFalse($this->isStale($payroll->fresh()), 'Setelah dihitung ulang, tidak usang lagi.');
        $this->assertSame(20, $payroll->fresh()->total_late_minutes);

        $employee->update(['base_salary' => 3000000]);
        $this->assertTrue($this->isStale($payroll->fresh()), 'Gaji pokok karyawan berbeda dari slip.');

        $locked = $this->payroll($this->user('kasir'), ['status' => 'paid']);
        $this->assertFalse($this->isStale($locked), 'Slip yang sudah dibayar tidak pernah dianggap usang.');
    }

    public function test_recalculating_in_bulk_only_touches_drafts(): void
    {
        $draftOwner = $this->user('kasir');
        $draft = $this->payroll($draftOwner);
        $paid = $this->payroll($this->user('kasir'), ['status' => 'paid']);
        $this->attend($draftOwner, '2026-09-12', 'alpha');
        $this->actingAs($this->user('direksi', ['store_id' => null]), 'web');

        Livewire::test(ListPayrolls::class)->callTableBulkAction('bulkRecalculate', [$draft, $paid]);

        $this->assertSame(1, $draft->fresh()->alpha_days);
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame(0, $paid->fresh()->alpha_days);
    }

    public function test_full_access_can_undo_a_wrong_paid_mark_with_a_reason_but_not_once_it_was_posted_to_a_journal(): void
    {
        $employee = $this->user('kasir');
        $admin = $this->user('direksi', ['store_id' => null]);
        $wrong = $this->payroll($employee, ['status' => 'paid', 'paid_by' => $admin->id, 'paid_at' => now()]);
        $this->mock(PushNotificationService::class, fn ($mock) => $mock->shouldReceive('sendToUsers')->atLeast()->once());
        $this->actingAs($admin, 'web');

        Livewire::test(ListPayrolls::class)
            ->assertTableActionVisible('unpay', $wrong)
            ->callTableAction('unpay', $wrong, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required'])
            ->callTableAction('unpay', $wrong, data: ['reason' => 'Salah pilih karyawan'])
            ->assertHasNoTableActionErrors();

        $fresh = $wrong->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->paid_by);
        $this->assertNull($fresh->paid_at);
        $this->assertTrue(\Spatie\Activitylog\Models\Activity::where('log_name', 'payroll')->where('subject_id', $wrong->id)->where('description', 'like', '%Salah pilih karyawan%')->exists());

        $posted = $this->payroll($this->user('kasir'), ['status' => 'pending_approval', 'payment_requested_by' => $admin->id, 'payment_requested_at' => now()]);
        Livewire::test(ListPayrolls::class)->callTableAction('approvePayment', $posted);
        $this->assertNotNull($posted->fresh()->journal_entry_id);
        Livewire::test(ListPayrolls::class)->assertTableActionHidden('unpay', $posted->fresh());

        $this->actingAs($this->user('spv_finance', ['store_id' => null]), 'web');
        Livewire::test(ListPayrolls::class)->assertTableActionHidden('unpay', $this->payroll($this->user('kasir'), ['status' => 'paid']));
    }

    // ------------------------------------------------------------- rekening & perapian daftar

    private function resourceCall(string $method, ...$args)
    {
        $reflection = new \ReflectionMethod(PayrollResource::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$args);
    }

    public function test_the_payment_modal_shows_where_to_transfer_or_warns_when_the_account_is_missing(): void
    {
        $with = $this->user('kasir', ['bank_name' => 'BCA', 'bank_account_number' => '1234567890', 'bank_account_holder_name' => 'Rina Pengaju']);
        $without = $this->user('kasir', ['name' => 'Budi Tanpa Rekening']);

        $this->assertSame('Transfer ke: BCA 1234567890 a.n. Rina Pengaju.', $this->resourceCall('bankLine', $with));
        $this->assertStringContainsString('Budi Tanpa Rekening belum punya nomor rekening', $this->resourceCall('bankLine', $without));

        $records = collect([$this->payroll($with)->load('user'), $this->payroll($without)->load('user')]);
        $summary = $this->resourceCall('selectionSummary', $records, true);
        $this->assertStringContainsString('2 slip terpilih', $summary);
        $this->assertStringContainsString('1 karyawan belum punya nomor rekening (Budi Tanpa Rekening)', $summary);
        $this->assertStringNotContainsString('PERHATIAN', $this->resourceCall('selectionSummary', $records));
    }

    public function test_the_list_opens_on_the_latest_period_and_hides_the_detail_columns_by_default(): void
    {
        $this->actingAs($this->user('direksi', ['store_id' => null]), 'web');
        $latest = $this->payroll($this->user('kasir'), ['period_month' => '2026-09-01']);
        $older = $this->payroll($this->user('kasir'), ['period_month' => '2026-08-01']);

        Livewire::test(ListPayrolls::class)
            ->assertCanSeeTableRecords([$latest])->assertCanNotSeeTableRecords([$older])
            ->removeTableFilter('period_month')
            ->assertCanSeeTableRecords([$latest, $older]);

        $component = Livewire::test(ListPayrolls::class);
        foreach (['store.name', 'total_late_minutes', 'late_violation_days', 'alpha_days', 'user.bank_account_number', 'base_salary'] as $hidden) {
            $component->assertTableColumnExists($hidden);
            $this->assertTrue($component->instance()->getTable()->getColumn($hidden)->isToggledHiddenByDefault(), "{$hidden} disembunyikan bawaan");
        }
    }

    // ------------------------------------------------------------- karyawan yang berhenti (Hari Kerja Terakhir)

    public function test_a_leaver_is_paid_proportionally_up_to_the_last_work_day_and_later_alpha_is_ignored(): void
    {
        // September 2026: 26 hari kerja x 9 jam = 234 jam. 1-15 September, tanpa Minggu 6 dan 13 = 13 hari = 117 jam = setengah bulan.
        $leaver = $this->user('kasir', ['last_work_date' => '2026-09-15']);
        $this->attend($leaver, '2026-09-10', 'alpha');
        $this->attend($leaver, '2026-09-22', 'alpha');

        $payroll = Payroll::generateForMonth($leaver, $this->month);

        $this->assertEquals(1300000, $payroll->prorated_base_salary);
        $this->assertSame(1, $payroll->alpha_days, 'Alpha sesudah hari kerja terakhir tidak dihitung.');
    }

    public function test_someone_who_joined_and_left_in_the_same_month_gets_only_the_days_between(): void
    {
        // 8-15 September tanpa Minggu 13 = 7 hari x 9 jam = 63 jam dari 234 jam.
        $both = $this->user('kasir', ['join_date' => '2026-09-08', 'last_work_date' => '2026-09-15']);

        $this->assertEquals(round(2600000 * 63 / 234, 2), Payroll::generateForMonth($both, $this->month)->prorated_base_salary);
    }

    public function test_generate_includes_inactive_leavers_of_that_month_and_skips_those_who_left_earlier(): void
    {
        $active = $this->user('kasir');
        $leaverInMonth = $this->user('kasir', ['is_active' => false, 'last_work_date' => '2026-09-20']);
        $leftEarlier = $this->user('kasir', ['is_active' => false, 'last_work_date' => '2026-08-20']);
        $inactiveNoDate = $this->user('kasir', ['is_active' => false]);
        $this->actingAs($this->user('direksi', ['store_id' => null]), 'web');

        Livewire::test(ListPayrolls::class)
            ->callTableAction('generate', data: ['month' => '2026-09-01', 'store_id' => $this->store->id]);

        $this->assertTrue(Payroll::where('user_id', $active->id)->exists());
        $this->assertTrue(Payroll::where('user_id', $leaverInMonth->id)->exists(), 'Gaji terakhir tetap dibuat walau akun sudah nonaktif.');
        $this->assertFalse(Payroll::where('user_id', $leftEarlier->id)->exists());
        $this->assertFalse(Payroll::where('user_id', $inactiveNoDate->id)->exists());
    }

    public function test_daily_absence_marking_stops_after_the_last_work_day(): void
    {
        $stayer = $this->user('kasir');
        $leaver = $this->user('kasir', ['last_work_date' => '2026-10-02']);

        Carbon::setTestNow('2026-10-07 01:00:00');
        try {
            $this->artisan('attendance:mark-absences')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(1, Attendance::where('user_id', $stayer->id)->where('entry_type', 'alpha')->count());
        $this->assertSame(0, Attendance::where('user_id', $leaver->id)->count(), 'Tidak ada Alpha sesudah hari kerja terakhir.');
    }

    public function test_the_requester_gets_one_summary_when_a_bulk_approval_covers_their_slips(): void
    {
        $spv = $this->user('spv_finance', ['store_id' => null]);
        $admin = $this->user('direksi', ['store_id' => null]);
        $a = $this->payroll($this->user('kasir'), ['status' => 'pending_approval', 'payment_requested_by' => $spv->id, 'payment_requested_at' => now()]);
        $b = $this->payroll($this->user('kasir'), ['status' => 'pending_approval', 'payment_requested_by' => $spv->id, 'payment_requested_at' => now()]);
        $pushes = [];
        $this->mock(PushNotificationService::class, function ($mock) use (&$pushes) {
            $mock->shouldReceive('sendToUsers')->andReturnUsing(function (array $ids, string $title, string $body) use (&$pushes) {
                $pushes[] = [$ids, $title, $body];
            });
        });
        $this->actingAs($admin, 'web');

        Livewire::test(ListPayrolls::class)->callTableBulkAction('bulkApprove', [$a, $b]);

        $toRequester = array_values(array_filter($pushes, fn ($p) => $p[0] === [$spv->id]));
        $this->assertCount(1, $toRequester, 'Satu ringkasan untuk pengaju, bukan satu per slip.');
        $this->assertSame('Pengajuan Pembayaran Gaji Disetujui', $toRequester[0][1]);
        $this->assertStringContainsString('2 slip gaji', $toRequester[0][2]);
        $this->assertCount(2, array_filter($pushes, fn ($p) => $p[1] === 'Gaji Sudah Dibayar'), 'Tiap karyawan tetap diberi tahu.');
    }
}
