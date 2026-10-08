<?php

namespace Tests\Feature;

use App\Exports\AttendanceReportExport;
use App\Filament\Pages\AttendanceReport;
use App\Models\Attendance;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Models\WorkSchedule;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Laporan Absensi: rincian harian dari tabel Absensi + kartu ringkasan (tepat waktu, telat, pulang cepat, masuk lebih
 * awal, lembur, alpha, izin) dan "Pola Ketepatan Waktu per Karyawan" terhadap Jadwal Kerja; cakupan toko; sanitasi URL;
 * Excel/PDF + log. "Hari ini" dibekukan di 8 Oktober 2026.
 */
class AttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;
    private Store $storeB;
    private User $andi;
    private User $budi;
    private User $citra;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-08 10:00:00');
        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->andi = $this->user('kasir', $this->storeA, ['name' => 'Andi']);
        $this->budi = $this->user('kasir', $this->storeA, ['name' => 'Budi']);
        $this->citra = $this->user('kasir', $this->storeB, ['name' => 'Citra']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store?->id], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function att(User $user, string $date, string $type, ?string $in = null, ?string $out = null, int $late = 0, int $early = 0): Attendance
    {
        return Attendance::create([
            'user_id' => $user->id, 'store_id' => $user->store_id, 'date' => $date, 'entry_type' => $type,
            'clock_in_at' => $in ? "{$date} {$in}:00" : null, 'clock_out_at' => $out ? "{$date} {$out}:00" : null,
            'late_minutes' => $late, 'early_leave_minutes' => $early,
        ]);
    }

    /**
     * Andi (jadwal Pagi 08:00–17:00): 5 Okt masuk 07:30 pulang 19:00 (lebih awal + lembur); 6 Okt masuk 08:30 pulang
     * 16:00 (telat 30, pulang cepat 60). Budi (tanpa jadwal): 5 Okt manual 08:00–17:00, 6 Okt alpha, 7 Okt izin.
     * Citra (toko B, tanpa jadwal): 5 Okt tugas lapangan. Di luar rentang: Andi 30 Sep.
     */
    private function october(): void
    {
        $shift = Shift::create(['store_id' => $this->storeA->id, 'name' => 'Pagi', 'start_time' => '08:00', 'end_time' => '17:00', 'is_active' => true]);
        $schedule = WorkSchedule::create([
            'store_id' => $this->storeA->id, 'name' => 'Reguler', 'is_active' => true,
            'days' => collect(WorkSchedule::DAYS)->map(fn ($day) => ['day' => $day, 'shift_id' => $shift->id])->toArray(),
        ]);
        EmployeeScheduleAssignment::assignBulk($schedule, [$this->andi->id], Carbon::parse('2026-09-20'), null);

        $this->att($this->andi, '2026-10-05', 'clock', '07:30', '19:00');
        $this->att($this->andi, '2026-10-06', 'clock', '08:30', '16:00', 30, 60);
        $this->att($this->budi, '2026-10-05', 'manual', '08:00', '17:00');
        $this->att($this->budi, '2026-10-06', 'alpha');
        $this->att($this->budi, '2026-10-07', 'leave');
        $this->att($this->citra, '2026-10-05', 'field_duty', '08:00', '17:00');
        $this->att($this->andi, '2026-09-30', 'clock', '08:00', '17:00');
    }

    private function page(array $data = [], ?User $as = null)
    {
        $this->actingAs($as ?? $this->user('super_admin'), 'web');
        $page = Livewire::test(AttendanceReport::class);
        foreach ($data as $key => $value) {
            $page->set("data.{$key}", $value);
        }

        return $page;
    }

    private function report(array $data = [], ?User $as = null): array
    {
        return $this->page($data, $as)->instance()->getResult();
    }

    public function test_summary_cards_count_each_kind_of_attendance(): void
    {
        $this->october();

        $result = $this->report();

        $this->assertCount(6, $result['rows'], 'Absensi 30 September tidak ikut.');
        $this->assertSame(3, $result['onTimeCount'], 'Andi 5 Okt, Budi (manual) 5 Okt, Citra (tugas lapangan); alpha/izin/telat tidak.');
        $this->assertSame(1, $result['lateCount']);
        $this->assertSame(1, $result['earlyLeaveCount']);
        $this->assertSame(1, $result['alphaCount']);
        $this->assertSame(1, $result['leaveCount']);
        $this->assertSame(1, $result['earlyArrivalCount'], 'Hanya Andi 5 Okt (07:30 < 08:00), karyawan tanpa jadwal tidak bisa dibandingkan.');
        $this->assertSame(1, $result['overtimeCount']);
    }

    public function test_pattern_per_employee_counts_no_schedule_only_for_clocked_days(): void
    {
        $this->october();

        $pattern = $this->report()['patternByUser']->keyBy(fn ($row) => $row['user']->name);

        $this->assertSame(['Andi', 'Budi', 'Citra'], $pattern->keys()->all(), 'Urut nama.');
        $this->assertSame([1, 1, 1, 1, 0], [$pattern['Andi']['lateCount'], $pattern['Andi']['earlyLeaveCount'], $pattern['Andi']['earlyArrivalCount'], $pattern['Andi']['overtimeCount'], $pattern['Andi']['noScheduleCount']]);
        $this->assertSame(1, $pattern['Budi']['noScheduleCount'], 'Hanya absen manual 5 Okt; baris alpha & izin tidak punya jam masuk untuk dibandingkan.');
        $this->assertSame(1, $pattern['Citra']['noScheduleCount']);
    }

    public function test_date_range_is_inclusive_and_empty_ranges_hide_the_pattern_table(): void
    {
        $this->october();

        $this->assertCount(7, $this->report(['from' => '2026-09-30', 'to' => '2026-10-31'])['rows']);
        $this->assertCount(1, $this->report(['from' => '2026-09-30', 'to' => '2026-09-30'])['rows']);

        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])
            ->assertSee('Tidak ada data absensi pada rentang ini.')
            ->assertDontSee('Pola Ketepatan Waktu per Karyawan');
    }

    public function test_admin_store_filter_and_staff_lock(): void
    {
        $this->october();

        $b = $this->report(['store_id' => $this->storeB->id]);
        $this->assertSame(['Citra'], $b['rows']->map(fn ($a) => $a->user->name)->unique()->values()->all());

        $staff = $this->user('kasir', $this->storeA);
        $own = $this->report(['store_id' => $this->storeB->id], $staff);
        $this->assertEqualsCanonicalizing(['Andi', 'Budi'], $own['rows']->map(fn ($a) => $a->user->name)->unique()->values()->all(), 'Staf tidak bisa melihat toko lain.');
        $this->assertCount(5, $own['rows']);
    }

    public function test_an_account_without_a_store_sees_nothing(): void
    {
        $this->october();

        $result = $this->report([], $this->user('kasir', null));

        $this->assertCount(0, $result['rows']);
        $this->assertSame(0, $result['onTimeCount']);
    }

    public function test_access_follows_staff_area_and_the_menu_checkbox(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');
        $this->assertTrue(AttendanceReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['AttendanceReport']]), 'web');
        $this->assertTrue(AttendanceReport::canAccess());

        $this->actingAs($this->user('kasir', $this->storeA, ['menu_access' => ['BookingResource']]), 'web');
        $this->assertFalse(AttendanceReport::canAccess());
    }

    public function test_the_query_string_is_sanitised_and_dates_are_corrected(): void
    {
        $this->actingAs($this->user('super_admin'), 'web');

        $bad = Livewire::withQueryParams(['from' => 'kemarin', 'to' => '', 'cabang' => (string) $this->storeB->id])->test(AttendanceReport::class);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$bad->get('from'), $bad->get('to')]);
        $this->assertSame($this->storeB->id, $bad->get('storeIdFilter'));

        $reversed = Livewire::withQueryParams(['from' => '2026-10-20', 'to' => '2026-10-10'])->test(AttendanceReport::class);
        $this->assertSame('2026-10-20', $reversed->get('to'));

        $this->actingAs($this->user('kasir', $this->storeA), 'web');
        $this->assertNull(Livewire::withQueryParams(['cabang' => (string) $this->storeB->id])->test(AttendanceReport::class)->get('storeIdFilter'));

        $page = $this->page();
        $page->set('data.from', '2026-10-20')->set('data.to', '2026-10-10');
        $this->assertSame('2026-10-20', Carbon::parse($page->get('data.to'))->toDateString());

        foreach (['last_month' => ['2026-09-01', '2026-09-30'], 'this_quarter' => ['2026-10-01', '2026-12-31'], 'ytd' => ['2026-01-01', '2026-10-08'], 'last_year' => ['2025-01-01', '2025-12-31']] as $preset => $range) {
            $page->set('data.preset', $preset);
            $this->assertSame($range, [$page->get('data.from'), $page->get('data.to')], $preset);
        }
    }

    public function test_page_shows_cards_pattern_table_and_detail_rows(): void
    {
        $this->october();

        $this->page()
            ->assertSuccessful()
            ->assertSee('Tepat Waktu')
            ->assertSee('Masuk Lebih Awal')
            ->assertSee('Lembur/Pulang Lambat')
            ->assertSee('Pola Ketepatan Waktu per Karyawan')
            ->assertSee('Tanpa Jadwal')
            ->assertSee('Andi')
            ->assertSee('Toko B')
            ->assertSee('07:30')
            ->assertSee('19:00')
            ->assertSee('30 menit')
            ->assertSee('60 menit')
            ->assertSee('Clock In/Out')
            ->assertSee('Tugas Lapangan')
            ->assertSee('Alpha')
            ->assertSee('Izin/Cuti');
    }

    public function test_excel_rows_are_aligned_with_headings(): void
    {
        $this->october();

        $export = new AttendanceReportExport($this->report());
        $rows = collect($export->array())->keyBy(fn ($r) => $r[0] . '|' . $r[2]);

        $this->assertSame(['Nama', 'Toko', 'Tanggal', 'Absen Masuk', 'Absen Keluar', 'Terlambat (Menit)', 'Pulang Cepat (Menit)', 'Jenis'], $export->headings());
        $this->assertCount(6, $rows);
        $this->assertSame(['Andi', 'Toko A', '2026-10-06', '08:30', '16:00', 30, 60, 'Clock In/Out'], $rows['Andi|2026-10-06']);
        $this->assertSame(['Budi', 'Toko A', '2026-10-06', '-', '-', 0, 0, 'Alpha'], $rows['Budi|2026-10-06']);
        $this->assertSame('Izin/Cuti', $rows['Budi|2026-10-07'][7]);
        $this->assertSame('Tugas Lapangan', $rows['Citra|2026-10-05'][7]);
        $this->assertSame('Manual', $rows['Budi|2026-10-05'][7]);
    }

    public function test_exports_download_and_the_log_records_the_effective_store(): void
    {
        $this->october();
        $staff = $this->user('kasir', $this->storeA);
        Excel::fake();

        $page = $this->page(['store_id' => $this->storeB->id], $staff);
        $page->callAction('exportExcel')->assertHasNoActionErrors();
        $page->callAction('exportPdf')->assertHasNoActionErrors();

        Excel::assertDownloaded('laporan-absensi-20261008-100000.xlsx');
        $logs = Activity::where('log_name', 'report_export')->where('causer_id', $staff->id)->get();
        $this->assertCount(2, $logs);
        $this->assertSame('attendance', $logs->first()->properties['report']);
        $this->assertSame([$this->storeA->id, $this->storeA->id], $logs->map(fn ($l) => $l->properties['store_id'])->all());
    }

    public function test_pdf_renders_with_data_and_when_empty(): void
    {
        $this->page(['from' => '2026-01-01', 'to' => '2026-01-31'])->callAction('exportPdf')->assertHasNoActionErrors();

        $this->october();
        $this->page()->callAction('exportPdf')->assertHasNoActionErrors();
    }
}
