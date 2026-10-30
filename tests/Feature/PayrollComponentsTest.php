<?php

namespace Tests\Feature;

use App\Filament\Resources\PayrollItemCategoryResource;
use App\Filament\Resources\PayrollItemCategoryResource\Pages\CreatePayrollItemCategory;
use App\Filament\Resources\PayrollResource\Pages\ListPayrolls;
use App\Models\Attendance;
use App\Models\AttendanceDeductionRule;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\PayrollItemCategory;
use App\Models\Store;
use App\Models\User;
use App\Services\PayrollItemService;
use App\Services\PayrollPostingService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Karyawan Fase 1 (2026-10-29): komponen gaji (tunjangan, bonus, THR, uang kompensasi, potongan BPJS/PPh 21/kasbon) yang angkanya dihitung
 * akuntan di luar sistem dan hanya dicatat di slip. Kategori punya akun pembukuan yang bisa diatur; jurnal gaji ikut memuatnya dan tetap balance.
 */
class PayrollComponentsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Carbon $month;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        PayrollItemCategory::ensureDefaults();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->month = Carbon::parse('2026-09-01');
        $this->store = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true,
            'opening_hours' => [['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], 'open' => '09:00', 'close' => '18:00'], ['days' => ['sun'], 'closed' => true]],
        ]);
        AttendanceDeductionRule::create(['effective_from' => '2026-01-01', 'late_block_minutes' => 15, 'late_deduction_per_block' => 50000, 'alpha_fine_per_day' => 0]);

        $this->admin = $this->user('super_admin');
    }

    private function user(string $role = 'kasir', array $extra = []): User
    {
        $user = User::create($extra + ['name' => 'Karyawan ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'base_salary' => 5000000, 'join_date' => '2025-01-01', 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function cat(string $code): PayrollItemCategory
    {
        return PayrollItemCategory::where('code', $code)->firstOrFail();
    }

    private function row(string $code, float $amount, array $extra = []): array
    {
        return ['payroll_item_category_id' => $this->cat($code)->id, 'amount' => $amount] + $extra;
    }

    private function payroll(?User $user = null, ?Carbon $month = null): Payroll
    {
        return Payroll::generateForMonth($user ?? $this->user(), $month ?? $this->month);
    }

    private function accountId(string $code): int
    {
        return (int) ChartOfAccount::where('code', $code)->value('id');
    }

    // ------------------------------------------------------------- kategori dan akun bawaan

    public function test_default_categories_and_their_accounts_are_created_once_and_never_overwrite_edits(): void
    {
        foreach (['6130', '6140', '6145', '6150', '2180', '2190'] as $code) {
            $this->assertNotNull(ChartOfAccount::where('code', $code)->first(), "Akun {$code} harus ada.");
        }

        $this->assertSame($this->accountId('2180'), $this->cat('bpjs_kesehatan')->chart_of_account_id);
        $this->assertSame($this->accountId('2130'), $this->cat('pph21')->chart_of_account_id, 'PPh 21 ke Hutang Pajak yang sudah ada.');
        $this->assertSame($this->accountId('1120'), $this->cat('kasbon')->chart_of_account_id, 'Kasbon ke Piutang Karyawan yang sudah ada.');
        $this->assertSame($this->accountId('6145'), $this->cat('thr')->chart_of_account_id);
        $this->assertSame($this->accountId('6140'), $this->cat('bonus')->chart_of_account_id, 'Bonus ke akun Beban Bonus/Insentif bawaan seeder.');
        $this->assertTrue($this->cat('tunjangan_tetap')->isEarning());
        $this->assertFalse($this->cat('pph21')->isEarning());

        $count = PayrollItemCategory::count();
        $this->cat('pph21')->update(['chart_of_account_id' => $this->accountId('2190'), 'name' => 'PPh 21 (edit akuntan)']);

        PayrollItemCategory::ensureDefaults();

        $this->assertSame($count, PayrollItemCategory::count(), 'Tidak menggandakan.');
        $this->assertSame($this->accountId('2190'), $this->cat('pph21')->chart_of_account_id, 'Edit akuntan tidak ditimpa.');
    }

    // ------------------------------------------------------------- perhitungan slip

    public function test_components_adjust_the_net_pay_and_the_totals(): void
    {
        $payroll = $this->payroll();
        $this->assertEquals(5000000, $payroll->net_pay);

        app(PayrollItemService::class)->sync($payroll, [
            $this->row('tunjangan_tetap', 500000, ['is_recurring' => true]),
            $this->row('thr', 5000000),
            $this->row('bpjs_kesehatan', 50000, ['is_recurring' => true]),
            $this->row('pph21', 125000),
            $this->row('kasbon', 200000, ['label' => 'Kasbon Agustus']),
        ], $this->admin->id);

        $fresh = $payroll->fresh();
        $this->assertEquals(5500000, $fresh->total_additions);
        $this->assertEquals(375000, $fresh->total_item_deductions);
        $this->assertEquals(5000000 + 5500000 - 375000, $fresh->net_pay);
        $this->assertSame(5, $fresh->items()->count());
        $this->assertSame('Kasbon Agustus', $fresh->items()->where('label', 'Kasbon Agustus')->first()->displayLabel());
        $this->assertSame('Tunjangan Tetap', $fresh->items()->where('payroll_item_category_id', $this->cat('tunjangan_tetap')->id)->first()->displayLabel());
    }

    public function test_attendance_deductions_and_components_combine_and_editing_or_removing_recalculates(): void
    {
        $user = $this->user();
        Attendance::create(['user_id' => $user->id, 'store_id' => $this->store->id, 'date' => '2026-09-02', 'entry_type' => 'clock', 'late_minutes' => 30]);   // 2 blok = 100.000
        $payroll = $this->payroll($user);
        $this->assertEquals(4900000, $payroll->net_pay);

        $service = app(PayrollItemService::class);
        $service->sync($payroll, [$this->row('bonus', 300000), $this->row('bpjs_ketenagakerjaan', 100000)]);
        $this->assertEquals(4900000 + 300000 - 100000, $payroll->fresh()->net_pay);

        $items = $payroll->fresh()->items()->orderBy('id')->get();
        $service->sync($payroll->fresh(), [$this->row('bonus', 400000, ['id' => $items[0]->id])]);   // bpjs dihapus, bonus diubah

        $fresh = $payroll->fresh();
        $this->assertSame(1, $fresh->items()->count());
        $this->assertEquals(400000, $fresh->total_additions);
        $this->assertEquals(0, $fresh->total_item_deductions);
        $this->assertEquals(4900000 + 400000, $fresh->net_pay);
    }

    public function test_regenerating_a_draft_keeps_its_components(): void
    {
        $user = $this->user();
        $payroll = $this->payroll($user);
        app(PayrollItemService::class)->sync($payroll, [$this->row('tunjangan_tetap', 500000)]);

        Attendance::create(['user_id' => $user->id, 'store_id' => $this->store->id, 'date' => '2026-09-03', 'entry_type' => 'alpha']);
        $again = Payroll::generateForMonth($user, $this->month);

        $this->assertSame($payroll->id, $again->id);
        $this->assertSame(1, $again->items()->count());
        $this->assertEquals(500000, $again->total_additions);
        $this->assertEquals(5000000 + 500000 - $again->total_deduction, $again->net_pay);
        $this->assertGreaterThan(0, (float) $again->total_deduction);
    }

    public function test_components_cannot_be_changed_once_the_payroll_is_not_a_draft(): void
    {
        $payroll = $this->payroll();
        app(PayrollItemService::class)->sync($payroll, [$this->row('bonus', 100000)]);
        $item = $payroll->fresh()->items()->firstOrFail();

        $payroll->update(['status' => 'pending_approval']);

        try {
            app(PayrollItemService::class)->sync($payroll->fresh(), [$this->row('bonus', 999999)]);
            $this->fail('Seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('masih draft', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $item->update(['amount' => 1]);
    }

    public function test_the_service_rejects_invalid_amounts_and_inactive_categories(): void
    {
        $payroll = $this->payroll();

        try {
            app(PayrollItemService::class)->sync($payroll, [$this->row('bonus', 0)]);
            $this->fail('Nominal 0 harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('lebih dari 0', $e->getMessage());
        }

        $this->cat('bonus')->update(['is_active' => false]);
        $this->expectExceptionMessage('tidak valid atau sudah dinonaktifkan');
        app(PayrollItemService::class)->sync($payroll, [$this->row('bonus', 1000)]);
    }

    // ------------------------------------------------------------- salin dari bulan lalu

    public function test_recurring_components_are_copied_from_last_month_only_once(): void
    {
        $user = $this->user();
        $august = Payroll::generateForMonth($user, Carbon::parse('2026-08-01'));
        app(PayrollItemService::class)->sync($august, [
            $this->row('tunjangan_tetap', 500000, ['is_recurring' => true]),
            $this->row('bpjs_kesehatan', 50000, ['is_recurring' => true]),
            $this->row('thr', 5000000),                               // sekali saja, tidak disalin
        ]);

        $september = $this->payroll($user);
        $service = app(PayrollItemService::class);

        $this->assertSame(2, $service->copyRecurring($september));
        $this->assertSame(0, $service->copyRecurring($september->fresh()), 'Tekan ulang tidak menggandakan.');

        $fresh = $september->fresh();
        $this->assertEqualsCanonicalizing(['tunjangan_tetap', 'bpjs_kesehatan'], $fresh->items()->with('category')->get()->pluck('category.code')->all());
        $this->assertEquals(5000000 + 500000 - 50000, $fresh->net_pay);

        $alone = $this->payroll($this->user());
        $this->assertSame(0, $service->copyRecurring($alone), 'Tanpa slip bulan lalu tidak ada yang disalin.');
    }

    // ------------------------------------------------------------- jurnal

    private function postPayroll(Payroll $payroll): JournalEntry
    {
        $payroll->forceFill(['paid_by' => $this->admin->id, 'paid_at' => now()])->save();

        return app(PayrollPostingService::class)->post($payroll->fresh());
    }

    public function test_the_journal_carries_each_component_to_its_own_account_and_balances(): void
    {
        $payroll = $this->payroll();
        app(PayrollItemService::class)->sync($payroll, [
            $this->row('tunjangan_tetap', 500000),
            $this->row('thr', 5000000),
            $this->row('bpjs_kesehatan', 50000),
            $this->row('bpjs_ketenagakerjaan', 150000),
            $this->row('pph21', 125000),
            $this->row('kasbon', 200000),
        ]);

        $entry = $this->postPayroll($payroll->fresh());
        $lines = $entry->lines;

        $this->assertEquals($lines->sum('debit'), $lines->sum('credit'), 'Jurnal harus balance.');
        $byCode = fn (string $code, string $side) => (float) $lines->firstWhere('chart_of_account_id', $this->accountId($code))?->{$side};

        $this->assertEquals(5000000, $byCode('6110', 'debit'));
        $this->assertEquals(500000, $byCode('6130', 'debit'), 'Tunjangan ke Beban Tunjangan & BPJS (bawaan seeder).');
        $this->assertEquals(5000000, $byCode('6145', 'debit'), 'THR ke Beban THR.');
        $this->assertEquals(200000, $byCode('2180', 'credit'), 'Dua potongan BPJS digabung ke Hutang BPJS.');
        $this->assertEquals(125000, $byCode('2130', 'credit'), 'PPh 21 ke Hutang Pajak.');
        $this->assertEquals(200000, $byCode('1120', 'credit'), 'Cicilan kasbon mengurangi Piutang Karyawan.');
        $this->assertEquals(5000000 + 5500000 - 525000, $byCode('1101', 'credit'), 'Kas = gaji bersih.');
    }

    public function test_attendance_deductions_still_go_to_their_own_account_next_to_components(): void
    {
        $user = $this->user();
        Attendance::create(['user_id' => $user->id, 'store_id' => $this->store->id, 'date' => '2026-09-02', 'entry_type' => 'clock', 'late_minutes' => 30]);
        $payroll = $this->payroll($user);
        app(PayrollItemService::class)->sync($payroll, [$this->row('bonus', 300000), $this->row('pph21', 100000)]);

        $lines = $this->postPayroll($payroll->fresh())->lines;

        $this->assertEquals($lines->sum('debit'), $lines->sum('credit'));
        $this->assertEquals(100000, (float) $lines->firstWhere('chart_of_account_id', $this->accountId('6120'))->credit, 'Potongan telat tetap ke 6120.');
        $this->assertEquals(100000, (float) $lines->firstWhere('chart_of_account_id', $this->accountId('2130'))->credit);
    }

    public function test_a_journal_still_balances_when_the_deductions_exceed_the_pay(): void
    {
        $payroll = $this->payroll($this->user('kasir', ['base_salary' => 1000000]));
        app(PayrollItemService::class)->sync($payroll, [
            $this->row('bpjs_kesehatan', 700000), $this->row('pph21', 500000), $this->row('kasbon', 800000),
        ]);

        $fresh = $payroll->fresh();
        $this->assertEquals(0, $fresh->net_pay, 'Gaji bersih tidak pernah negatif.');

        $lines = $this->postPayroll($fresh)->lines;

        $this->assertEquals($lines->sum('debit'), $lines->sum('credit'));
        $this->assertEquals(1000000, (float) $lines->sum('debit'));
        $this->assertNull($lines->firstWhere('chart_of_account_id', $this->accountId('1101')), 'Tidak ada kas keluar.');
    }

    public function test_posting_fails_with_a_clear_message_when_a_category_account_is_missing(): void
    {
        $payroll = $this->payroll();
        app(PayrollItemService::class)->sync($payroll, [$this->row('bonus', 100000)]);
        $this->cat('bonus')->update(['chart_of_account_id' => null]);

        $this->expectExceptionMessage('belum diatur atau nonaktif');
        $this->postPayroll($payroll->fresh());
    }

    public function test_payrolls_without_components_keep_the_old_journal(): void
    {
        $payroll = $this->payroll();

        $lines = $this->postPayroll($payroll)->lines;

        $this->assertCount(2, $lines);
        $this->assertEquals(5000000, (float) $lines->sum('debit'));
    }

    // ------------------------------------------------------------- panel dan API

    public function test_the_panel_edits_components_on_a_draft_and_hides_the_actions_otherwise(): void
    {
        $this->actingAs($this->admin, 'web');
        $payroll = $this->payroll();

        Livewire::test(ListPayrolls::class)
            ->callTableAction('components', $payroll, data: ['items' => [[
                'id' => null, 'payroll_item_category_id' => $this->cat('tunjangan_tetap')->id, 'amount' => 750000, 'label' => 'Tunjangan Makan', 'is_recurring' => true, 'notes' => null,
            ]]])
            ->assertHasNoTableActionErrors();

        $fresh = $payroll->fresh();
        $this->assertEquals(750000, $fresh->total_additions);
        $this->assertSame('Tunjangan Makan', $fresh->items()->firstOrFail()->label);

        $payroll->update(['status' => 'paid']);
        Livewire::test(ListPayrolls::class)
            ->assertTableActionHidden('components', $payroll->fresh())
            ->assertTableActionHidden('copyComponents', $payroll->fresh());
    }

    public function test_the_category_screen_lets_the_accountant_change_accounts_and_protects_used_categories(): void
    {
        $this->actingAs($this->admin, 'web');

        Livewire::test(CreatePayrollItemCategory::class)
            ->fillForm(['name' => 'Tunjangan Makan', 'code' => 'tunjangan_makan', 'type' => 'earning', 'chart_of_account_id' => $this->accountId('6130'), 'is_recurring_default' => true, 'sort_order' => 15, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNotNull(PayrollItemCategory::where('code', 'tunjangan_makan')->first());

        $used = $this->cat('bonus');
        $payroll = $this->payroll();
        app(PayrollItemService::class)->sync($payroll, [$this->row('bonus', 1000)]);

        $this->assertFalse(PayrollItemCategoryResource::canDelete($used->fresh()), 'Sudah dipakai: cukup dinonaktifkan.');
        $this->assertTrue(PayrollItemCategoryResource::canDelete($this->cat('tunjangan_makan')));
    }

    public function test_the_staff_app_receives_the_components_of_my_paid_slip(): void
    {
        $user = $this->user();
        $payroll = $this->payroll($user);
        app(PayrollItemService::class)->sync($payroll, [$this->row('tunjangan_tetap', 500000), $this->row('pph21', 125000)]);
        $payroll->fresh()->update(['status' => 'paid', 'paid_at' => now()]);

        $row = $this->actingAs($user, 'api')->getJson('/api/staff/payroll')->assertOk()->json('payrolls.0');

        $this->assertEquals(500000, $row['total_additions']);
        $this->assertEquals(125000, $row['total_item_deductions']);
        $this->assertEquals(
            [['label' => 'Tunjangan Tetap', 'type' => 'earning', 'amount' => 500000], ['label' => 'PPh 21', 'type' => 'deduction', 'amount' => 125000]],
            $row['items']
        );
    }

    public function test_default_staff_cannot_view_or_change_the_payroll_component_categories(): void
    {
        $category = $this->cat('bonus');

        $default = $this->user('kasir');
        $this->assertNull($default->menu_access);
        $this->actingAs($default, 'web');
        $this->assertFalse(PayrollItemCategoryResource::canViewAny());
        $this->assertFalse(PayrollItemCategoryResource::canCreate());
        $this->assertFalse(PayrollItemCategoryResource::canEdit($category));
        $this->assertFalse(PayrollItemCategoryResource::canDelete($this->cat('tunjangan_tetap')));
        Livewire::test(CreatePayrollItemCategory::class)->assertForbidden();

        $viewer = $this->user('kasir', ['menu_access' => [PayrollItemCategoryResource::class]]);
        $this->actingAs($viewer, 'web');
        $this->assertTrue(PayrollItemCategoryResource::canViewAny());
        $this->assertFalse(PayrollItemCategoryResource::canCreate());
        $this->assertFalse(PayrollItemCategoryResource::canEdit($category));

        $maintainer = $this->user('kasir', [
            'menu_access' => [PayrollItemCategoryResource::class],
            'menu_permissions' => [PayrollItemCategoryResource::class => ['create', 'update']],
        ]);
        $this->actingAs($maintainer, 'web');
        $this->assertTrue(PayrollItemCategoryResource::canCreate());
        $this->assertTrue(PayrollItemCategoryResource::canEdit($category));
        $this->assertFalse(PayrollItemCategoryResource::canDelete($this->cat('tunjangan_tetap')), 'Hapus butuh izin sendiri.');
    }

    public function test_the_category_list_shows_usage_filters_by_type_and_status_and_groups_additions_first(): void
    {
        $this->actingAs($this->admin, 'web');
        $used = $this->cat('bonus');
        $unused = $this->cat('tunjangan_tidak_tetap');
        $deduction = $this->cat('kasbon');
        app(PayrollItemService::class)->sync($this->payroll(), [$this->row('bonus', 1000)]);
        $unused->update(['is_active' => false]);

        Livewire::test(\App\Filament\Resources\PayrollItemCategoryResource\Pages\ListPayrollItemCategories::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('items_count', 1, $used)
            ->assertTableColumnStateSet('items_count', 0, $unused)
            ->assertSee('Tidak bisa dihapus, cukup nonaktifkan')
            ->filterTable('type', 'deduction')
            ->assertCanSeeTableRecords([$deduction])->assertCanNotSeeTableRecords([$used, $unused])
            ->resetTableFilters()
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$unused])->assertCanNotSeeTableRecords([$used, $deduction]);
    }

    // ------------------------------------------------------------- berhenti disalin & nama dibekukan

    public function test_a_component_stops_being_copied_after_its_last_month(): void
    {
        $user = $this->user();
        $july = Payroll::generateForMonth($user, Carbon::parse('2026-07-01'));
        $service = app(PayrollItemService::class);
        $service->sync($july, [
            $this->row('kasbon', 200000, ['is_recurring' => true, 'repeat_until' => '2026-09-15']),   // cicilan terakhir September
            $this->row('bpjs_kesehatan', 50000, ['is_recurring' => true]),                              // tanpa batas
        ]);
        $this->assertSame('2026-09-01', $july->items()->where('label', $this->cat('kasbon')->name)->first()->repeat_until->toDateString(), 'Disimpan sebagai awal bulan.');

        $august = Payroll::generateForMonth($user, Carbon::parse('2026-08-01'));
        $this->assertSame(2, $service->copyRecurring($august));

        $september = Payroll::generateForMonth($user, Carbon::parse('2026-09-01'));
        $this->assertSame(2, $service->copyRecurring($september), 'September masih ikut (bulan terakhir).');
        $this->assertNotNull($september->items()->whereHas('category', fn ($q) => $q->where('code', 'kasbon'))->first()->repeat_until, 'Batasnya ikut tersalin.');

        $october = Payroll::generateForMonth($user, Carbon::parse('2026-10-01'));
        $this->assertSame(1, $service->copyRecurring($october), 'Oktober hanya BPJS; kasbon sudah lewat bulan terakhirnya.');
        $this->assertSame(['bpjs_kesehatan'], $october->items()->with('category')->get()->pluck('category.code')->all());
    }

    public function test_the_last_month_cannot_be_before_the_slips_own_period_and_is_ignored_when_not_recurring(): void
    {
        $service = app(PayrollItemService::class);
        $payroll = $this->payroll();   // September 2026

        try {
            $service->sync($payroll, [$this->row('kasbon', 100000, ['is_recurring' => true, 'repeat_until' => '2026-08-01'])]);
            $this->fail('Bulan terakhir sebelum periode slip seharusnya ditolak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tidak boleh sebelum periode slip', $e->getMessage());
        }

        $service->sync($payroll, [$this->row('kasbon', 100000, ['is_recurring' => false, 'repeat_until' => '2027-01-01'])]);
        $this->assertNull($payroll->items()->first()->repeat_until);
    }

    public function test_a_blank_name_on_the_slip_is_frozen_to_the_category_name_so_renaming_the_category_leaves_old_slips_alone(): void
    {
        $payroll = $this->payroll();
        $category = $this->cat('bonus');
        app(PayrollItemService::class)->sync($payroll, [$this->row('bonus', 1000000)]);

        $item = $payroll->items()->firstOrFail();
        $this->assertSame($category->name, $item->label);

        $category->update(['name' => 'Insentif Penjualan']);

        $this->assertSame('Bonus / Insentif', $item->fresh()->displayLabel(), 'Slip yang sudah dibuat tidak ikut berubah.');

        $other = $this->payroll($this->user());
        app(PayrollItemService::class)->sync($other, [$this->row('bonus', 1000000)]);
        $this->assertSame('Insentif Penjualan', $other->items()->firstOrFail()->displayLabel(), 'Komponen baru memakai nama kategori yang baru.');
    }

    public function test_changing_the_category_of_an_untouched_name_follows_the_new_category(): void
    {
        $payroll = $this->payroll();
        $service = app(PayrollItemService::class);
        $service->sync($payroll, [$this->row('bonus', 1000000)]);
        $item = $payroll->items()->firstOrFail();

        $service->sync($payroll, [['id' => $item->id, 'payroll_item_category_id' => $this->cat('thr')->id, 'amount' => 1000000, 'label' => $item->label]]);

        $this->assertSame($this->cat('thr')->name, $item->fresh()->displayLabel());
    }

    public function test_default_categories_do_not_create_ledger_accounts_while_the_finance_module_is_off_and_get_them_when_it_is_on(): void
    {
        \App\Models\ChartOfAccount::whereIn('code', ['6145', '6150', '2180', '2190'])->delete();
        PayrollItemCategory::query()->delete();
        $accountsBefore = \App\Models\ChartOfAccount::count();

        config(['features.finance' => false]);
        PayrollItemCategory::ensureDefaults();

        $this->assertSame($accountsBefore, \App\Models\ChartOfAccount::count(), 'Tidak ada akun Bagan Akun baru saat modul keuangan mati.');
        $this->assertSame(11, PayrollItemCategory::count());
        $this->assertSame(0, PayrollItemCategory::whereNotNull('chart_of_account_id')->count());

        config(['features.finance' => true]);
        PayrollItemCategory::ensureDefaults();

        $this->assertGreaterThan($accountsBefore, \App\Models\ChartOfAccount::count());
        $this->assertSame(11, PayrollItemCategory::count(), 'Tidak menggandakan kategori.');
        $this->assertSame(0, PayrollItemCategory::whereNull('chart_of_account_id')->count(), 'Kategori bawaan mendapat akunnya begitu modul dinyalakan.');
        $this->assertSame($this->accountId('6130'), $this->cat('tunjangan_tetap')->chart_of_account_id);
    }
}
