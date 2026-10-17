<?php

namespace Tests\Feature;

use App\Filament\Resources\AttendanceDeductionRuleResource;
use App\Filament\Resources\AttendanceDeductionRuleResource\Pages\CreateAttendanceDeductionRule;
use App\Filament\Resources\AttendanceDeductionRuleResource\Pages\ListAttendanceDeductionRules;
use App\Models\Attendance;
use App\Models\AttendanceDeductionRule;
use App\Models\BlockedDate;
use App\Models\Payroll;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Karyawan Fase 0 (2026-10-28), aturan potongan absensi yang disepakati pemilik: telat per blok penuh dari TOTAL menit sebulan (tanpa
 * toleransi bebas), Alpha per jam kerja (jam istirahat dikurangi) ditambah denda tetap per hari, pulang cepat tidak dipotong, dan semua
 * nominal diisi staf lewat panel.
 *
 * Jam kerja Ginnva: Senin-Kamis 08.30-17.00 (istirahat 12.00-13.00 = 7,5 jam), Jumat (istirahat 11.30-13.00 = 7 jam), Sabtu 09.00-13.00
 * (istirahat 12.00-13.00 = 3 jam), Minggu tutup. September 2026 = 18 hari Sen-Kam, 4 Jumat, 4 Sabtu = 175 jam; gaji Rp5.250.000 -> Rp30.000/jam.
 */
class AttendanceDeductionRuleTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'super_admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->month = Carbon::parse('2026-09-01');
        $this->store = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true,
            'opening_hours' => [
                ['days' => ['mon', 'tue', 'wed', 'thu'], 'open' => '08:30', 'close' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00'],
                ['days' => ['fri'], 'open' => '08:30', 'close' => '17:00', 'break_start' => '11:30', 'break_end' => '13:00'],
                ['days' => ['sat'], 'open' => '09:00', 'close' => '13:00', 'break_start' => '12:00', 'break_end' => '13:00'],
                ['days' => ['sun'], 'closed' => true],
            ],
        ]);
    }

    private function rule(array $over = []): AttendanceDeductionRule
    {
        return AttendanceDeductionRule::create($over + ['effective_from' => '2026-01-01', 'late_block_minutes' => 15, 'late_deduction_per_block' => 50000, 'alpha_fine_per_day' => 0]);
    }

    private function employee(array $extra = []): User
    {
        $user = User::create($extra + ['name' => 'Karyawan ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'base_salary' => 5250000, 'join_date' => '2025-01-01', 'is_active' => true]);
        $user->assignRole('kasir');

        return $user;
    }

    private function attend(User $user, string $date, string $type = 'clock', int $late = 0, int $early = 0): Attendance
    {
        return Attendance::create(['user_id' => $user->id, 'store_id' => $user->store_id, 'date' => $date, 'entry_type' => $type, 'late_minutes' => $late, 'early_leave_minutes' => $early]);
    }

    // ------------------------------------------------------------- jam kerja efektif

    public function test_scheduled_minutes_follow_the_opening_hours_minus_the_break(): void
    {
        $this->assertSame(450, $this->store->scheduledMinutesOn(Carbon::parse('2026-09-07')), 'Senin 08.30-17.00 - istirahat 1 jam = 7,5 jam.');
        $this->assertSame(420, $this->store->scheduledMinutesOn(Carbon::parse('2026-09-04')), 'Jumat istirahat 1,5 jam = 7 jam.');
        $this->assertSame(180, $this->store->scheduledMinutesOn(Carbon::parse('2026-09-05')), 'Sabtu 09.00-13.00 - istirahat 1 jam = 3 jam.');
        $this->assertSame(0, $this->store->scheduledMinutesOn(Carbon::parse('2026-09-06')), 'Minggu tutup.');

        BlockedDate::create(['store_id' => $this->store->id, 'date' => '2026-09-08', 'reason' => 'Libur']);
        $this->assertSame(0, $this->store->fresh()->scheduledMinutesOn(Carbon::parse('2026-09-08')), 'Tanggal diblokir tidak dihitung.');

        $noBreak = Store::create(['city' => 'X', 'address' => 'Y', 'name' => 'Tanpa istirahat', 'is_active' => true, 'opening_hours' => [['days' => ['mon'], 'open' => '09:00', 'close' => '18:00']]]);
        $this->assertSame(540, $noBreak->scheduledMinutesOn(Carbon::parse('2026-09-07')));
    }

    // ------------------------------------------------------------- telat per blok

    public function test_lateness_is_charged_per_full_block_of_the_monthly_total_without_a_free_tolerance(): void
    {
        $this->rule();

        foreach ([[14, 0], [20, 1], [40, 2], [45, 3]] as [$minutes, $blocks]) {
            $user = $this->employee();
            // Total dipecah ke beberapa hari: yang dihitung total bulanan.
            $this->attend($user, '2026-09-02', 'clock', intdiv($minutes, 2));
            $this->attend($user, '2026-09-03', 'clock', $minutes - intdiv($minutes, 2));

            $payroll = Payroll::generateForMonth($user, $this->month);

            $this->assertSame($minutes, $payroll->total_late_minutes);
            $this->assertSame($blocks, $payroll->late_blocks, "{$minutes} menit = {$blocks} blok penuh.");
            $this->assertEquals($blocks * 50000, $payroll->late_deduction);
            $this->assertEquals(5250000 - $blocks * 50000, $payroll->net_pay);
        }
    }

    public function test_the_block_size_and_amount_come_from_the_rule_not_the_code(): void
    {
        $this->rule(['late_block_minutes' => 30, 'late_deduction_per_block' => 80000]);
        $user = $this->employee();
        $this->attend($user, '2026-09-02', 'clock', 65);

        $payroll = Payroll::generateForMonth($user, $this->month);

        $this->assertSame(2, $payroll->late_blocks, '65 menit / 30 = 2 blok penuh.');
        $this->assertSame(30, $payroll->late_block_minutes);
        $this->assertEquals(160000, $payroll->late_deduction);
    }

    // ------------------------------------------------------------- alpha per jam

    public function test_alpha_is_charged_by_the_working_hours_of_that_day(): void
    {
        $this->rule();
        $user = $this->employee();
        $this->attend($user, '2026-09-05', 'alpha'); // Sabtu 3 jam
        $this->attend($user, '2026-09-07', 'alpha'); // Senin 7,5 jam

        $payroll = Payroll::generateForMonth($user, $this->month);

        $this->assertEquals(175, $payroll->scheduled_hours_in_month);
        $this->assertEquals(30000, $payroll->hourly_rate);
        $this->assertEquals(10.5, $payroll->alpha_hours);
        $this->assertEquals(90000 + 225000, $payroll->alpha_deduction, 'Sabtu 3 jam x 30.000 + Senin 7,5 jam x 30.000.');
        $this->assertEquals(5250000 - 315000, $payroll->net_pay);
    }

    public function test_the_fixed_alpha_fine_is_added_per_alpha_day(): void
    {
        $this->rule(['alpha_fine_per_day' => 100000]);
        $user = $this->employee();
        $this->attend($user, '2026-09-05', 'alpha');
        $this->attend($user, '2026-09-07', 'alpha');

        $payroll = Payroll::generateForMonth($user, $this->month);

        $this->assertEquals(200000, $payroll->alpha_fine);
        $this->assertEquals(315000 + 200000, $payroll->alpha_deduction);
        $this->assertEquals(515000, $payroll->total_deduction);
        $this->assertEquals(5250000 - 515000, $payroll->net_pay);
    }

    public function test_approved_leave_and_early_leave_cost_nothing(): void
    {
        $this->rule();
        $user = $this->employee();
        $this->attend($user, '2026-09-08', 'leave');
        $this->attend($user, '2026-09-09', 'clock', 0, 240); // pulang cepat 4 jam: hanya dicatat

        $payroll = Payroll::generateForMonth($user, $this->month);

        $this->assertEquals(0, $payroll->total_deduction);
        $this->assertEquals(5250000, $payroll->net_pay);
    }

    public function test_a_store_without_opening_times_falls_back_to_the_daily_rate(): void
    {
        $this->rule();
        $legacy = Store::create(['city' => 'X', 'address' => 'Y', 'name' => 'Tanpa jam', 'is_active' => true, 'opening_hours' => [['days' => ['sun'], 'closed' => true]]]);
        $user = $this->employee(['store_id' => $legacy->id, 'base_salary' => 2600000]);
        $this->attend($user, '2026-09-02', 'alpha');

        $payroll = Payroll::generateForMonth($user, $this->month);

        $this->assertNull($payroll->scheduled_hours_in_month);
        $this->assertEquals(100000, $payroll->alpha_deduction, '2.600.000 / 26 hari buka = 100.000 per hari.');
    }

    public function test_a_joiner_mid_month_is_prorated_by_scheduled_hours(): void
    {
        $this->rule();
        $user = $this->employee(['join_date' => '2026-09-21']);

        $payroll = Payroll::generateForMonth($user, $this->month);

        // 21-30 September: Sen-Kam = 21,22,23,24,28,29,30 (7 hari x 7,5 jam), Jumat 25 (7 jam), Sabtu 26 (3 jam), Minggu tutup = 62,5 jam.
        $expectedHours = (7.5 * 7) + 7 + 3;
        $this->assertEquals(round(30000 * $expectedHours, 2), $payroll->prorated_base_salary);
    }

    // ------------------------------------------------------------- aturan: wajib ada, riwayat, perubahan

    public function test_payroll_cannot_be_generated_without_an_effective_rule(): void
    {
        $user = $this->employee();

        try {
            Payroll::generateForMonth($user, $this->month);
            $this->fail('Tanpa aturan potongan seharusnya ditolak.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Aturan Potongan Absensi belum diisi', $e->getMessage());
        }

        $this->rule(['effective_from' => '2026-10-01']);   // baru berlaku bulan depan
        $this->expectException(\InvalidArgumentException::class);
        Payroll::generateForMonth($user, $this->month);
    }

    public function test_each_month_uses_the_rule_in_force_for_that_month(): void
    {
        $this->rule(['effective_from' => '2026-01-01', 'late_deduction_per_block' => 50000]);
        $this->rule(['effective_from' => '2026-10-01', 'late_deduction_per_block' => 80000]);
        $user = $this->employee();
        $this->attend($user, '2026-09-02', 'clock', 30);
        $this->attend($user, '2026-10-02', 'clock', 30);

        $this->assertEquals(100000, Payroll::generateForMonth($user, Carbon::parse('2026-09-01'))->late_deduction);
        $this->assertEquals(160000, Payroll::generateForMonth($user, Carbon::parse('2026-10-01'))->late_deduction);
    }

    public function test_the_rule_resource_lets_staff_fill_the_amounts_and_locks_rules_already_used(): void
    {
        $admin = $this->employee();
        $admin->syncRoles(['super_admin']);
        $this->actingAs($admin, 'web');

        Livewire::test(CreateAttendanceDeductionRule::class)
            ->fillForm(['effective_from' => '2026-11-01', 'late_block_minutes' => 15, 'late_deduction_per_block' => 50000, 'alpha_fine_per_day' => 100000])
            ->call('create')
            ->assertHasNoFormErrors();

        $rule = AttendanceDeductionRule::firstOrFail();
        $this->assertEquals(50000, $rule->late_deduction_per_block);
        $this->assertSame($admin->id, $rule->created_by);

        Livewire::test(CreateAttendanceDeductionRule::class)
            ->fillForm(['effective_from' => '2026-11-01', 'late_block_minutes' => 15, 'late_deduction_per_block' => 1, 'alpha_fine_per_day' => 0])
            ->call('create')
            ->assertHasFormErrors(['effective_from']);

        $this->assertTrue(AttendanceDeductionRuleResource::canEdit($rule), 'Belum dipakai payroll: boleh diubah.');

        $user = $this->employee();
        Payroll::generateForMonth($user, Carbon::parse('2026-11-01'));

        $this->assertTrue($rule->fresh()->isInUse());
        $this->assertFalse(AttendanceDeductionRuleResource::canEdit($rule->fresh()), 'Sudah dipakai payroll: tidak boleh diubah, buat aturan baru.');
        $this->assertFalse(AttendanceDeductionRuleResource::canDelete($rule->fresh()));

        Livewire::test(ListAttendanceDeductionRules::class)->assertCanSeeTableRecords([$rule]);
    }

    public function test_only_users_with_the_menu_can_open_the_rule_screen(): void
    {
        $kasirWithoutMenu = $this->employee(['menu_access' => ['SomeOtherResource']]);
        $this->actingAs($kasirWithoutMenu, 'web');
        $this->assertFalse(AttendanceDeductionRuleResource::canViewAny());

        $kasirWithMenu = $this->employee(['menu_access' => [AttendanceDeductionRuleResource::class]]);
        $this->actingAs($kasirWithMenu, 'web');
        $this->assertTrue(AttendanceDeductionRuleResource::canViewAny());
    }
}
