<?php

namespace Tests\Feature;

use App\Exports\AttendanceReportExport;
use App\Exports\AttendanceSummaryExport;
use App\Filament\Pages\AttendanceReport;
use App\Models\Attendance;
use App\Models\EmployeeScheduleAssignment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
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
        $this->assertEqualsCanonicalizing(['Andi', 'Budi'], $own['rows']->map(fn ($a) => $a->user->name)->unique()->values()->all(), 'Staff tidak bisa melihat toko lain.');
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

    public function test_summary_by_employee_counts_each_person_once_in_name_order(): void
    {
        $this->october();

        $summary = collect($this->page()->instance()->summaryByEmployee($this->page()->instance()->getResult()['rows']))->keyBy('name');

        $this->assertSame(['Andi', 'Budi', 'Citra'], $summary->keys()->all());
        $this->assertSame([2, 1, 30, 1, 0, 0], [
            $summary['Andi']['present'], $summary['Andi']['late_count'], $summary['Andi']['late_minutes'],
            $summary['Andi']['early_leave_count'], $summary['Andi']['alpha'], $summary['Andi']['leave'],
        ], 'Andi: 5 dan 6 Okt hadir; 30 September di luar rentang.');
        $this->assertSame([1, 0, 0, 0, 1, 1], [
            $summary['Budi']['present'], $summary['Budi']['late_count'], $summary['Budi']['late_minutes'],
            $summary['Budi']['early_leave_count'], $summary['Budi']['alpha'], $summary['Budi']['leave'],
        ], 'Budi: 1 manual, 1 alpha, 1 izin.');
        $this->assertSame(1, $summary['Citra']['present'], 'Tugas lapangan dihitung hadir.');
        // Total jam kerja: Andi 07:30-19:00 (11,5) + 08:30-16:00 (7,5); Budi 08:00-17:00 (9), alpha/izin tidak punya jam; Citra 08:00-17:00 (9).
        $this->assertSame([19.0, 9.0, 9.0], [$summary['Andi']['work_hours'], $summary['Budi']['work_hours'], $summary['Citra']['work_hours']]);
        $this->assertSame([0, 0, 0], [$summary['Andi']['missing_clock_out'], $summary['Budi']['missing_clock_out'], $summary['Citra']['missing_clock_out']]);

        $this->att($this->budi, '2026-10-08', 'clock', '08:00');
        $again = collect($this->page()->instance()->summaryByEmployee($this->page()->instance()->getResult()['rows']))->keyBy('name');
        $this->assertSame(1, $again['Budi']['missing_clock_out'], 'Absen masuk tanpa absen keluar ditandai.');
        $this->assertSame(9.0, $again['Budi']['work_hours'], 'Hari tanpa jam keluar tidak menambah jam kerja.');
    }

    /** Satu baris ekspor sebagai larik judul-kolom => nilai, supaya tes tidak bergantung pada urutan kolom. */
    private function exportRows(array $data = []): array
    {
        $page = $this->page($data)->instance();
        $result = $page->getResult();
        $export = new AttendanceSummaryExport($page->summaryByEmployee($result['rows']), $result['from'], $result['to']);
        $headings = $export->headings();

        return [$export, array_map(fn (array $line) => array_combine($headings, $line), $export->array())];
    }

    public function test_summary_export_has_period_title_total_row_and_missing_clock_out_column(): void
    {
        $this->october();

        [$export, $rows] = $this->exportRows();

        $this->assertSame('Rekap 20261001-20261031', $export->title());
        $this->assertContains('Belum Absen Keluar (Hari)', $export->headings());
        $this->assertCount(4, $rows, '3 karyawan + 1 baris total.');
        $total = end($rows);
        $this->assertSame('TOTAL', $total['Karyawan']);
        $this->assertSame([4, 37.0], [$total['Hadir (Hari)'], $total['Total Jam Kerja']], 'Hadir: Andi 2 + Budi 1 + Citra 1; jam: 19 + 9 + 9.');
        $this->assertSame([1, 1], [$total['Alpha (Hari)'], $total['Izin/Cuti (Hari)']], 'Alpha 1 dan Izin/Cuti 1 (Budi).');
    }

    public function test_summary_export_has_employee_number_and_leave_columns_per_type(): void
    {
        $this->october();
        $this->andi->update(['employee_number' => 'EMP-001']);
        $sakit = LeaveType::create(['code' => 'sakit_x', 'name' => 'Sakit', 'category' => 'izin', 'deducts_annual_quota' => false, 'sort_order' => 210, 'is_active' => true]);
        // Budi izin 7 Okt (baris leave dari october()) dengan pengajuan Sakit; Andi juga izin 8 Okt dengan pengajuan Sakit.
        LeaveRequest::create(['user_id' => $this->budi->id, 'store_id' => $this->budi->store_id, 'type' => 'izin', 'leave_type_id' => $sakit->id, 'start_date' => '2026-10-07', 'end_date' => '2026-10-07', 'reason' => 'x', 'status' => 'approved']);
        $this->att($this->andi, '2026-10-08', 'leave');
        LeaveRequest::create(['user_id' => $this->andi->id, 'store_id' => $this->andi->store_id, 'type' => 'izin', 'leave_type_id' => $sakit->id, 'start_date' => '2026-10-08', 'end_date' => '2026-10-08', 'reason' => 'x', 'status' => 'approved']);

        [$export, $rows] = $this->exportRows();
        $byName = collect($rows)->keyBy('Karyawan');

        $this->assertSame('No. Karyawan', $export->headings()[0]);
        $this->assertSame('EMP-001', $byName['Andi']['No. Karyawan']);
        $this->assertSame('-', $byName['Budi']['No. Karyawan'], 'Belum punya nomor: strip.');
        $this->assertContains('  - Sakit (Hari)', $export->headings());
        $this->assertSame([1, 1, 0], [$byName['Andi']['  - Sakit (Hari)'], $byName['Budi']['  - Sakit (Hari)'], $byName['Citra']['  - Sakit (Hari)']]);
        $this->assertSame(2, $byName['TOTAL']['  - Sakit (Hari)'], 'Baris total ikut menjumlah kolom jenis.');
        $this->assertSame(2, $byName['TOTAL']['Izin/Cuti (Hari)'], 'Total Izin/Cuti tetap memuat semua baris leave.');
    }

    public function test_leave_without_a_matching_request_is_listed_as_without_request(): void
    {
        $this->october(); // Budi punya baris leave 7 Okt tanpa pengajuan

        [$export, $rows] = $this->exportRows();
        $budi = collect($rows)->firstWhere('Karyawan', 'Budi');

        $this->assertContains('  - Tanpa Pengajuan (Hari)', $export->headings());
        $this->assertSame(1, $budi['  - Tanpa Pengajuan (Hari)']);
    }

    public function test_summary_export_downloads_and_is_logged(): void
    {
        $this->october();
        Excel::fake();

        $this->page()->callAction('exportSummary')->assertHasNoActionErrors();

        Excel::assertDownloaded('rekap-absensi-karyawan-20261001-20261031.xlsx');
        $this->assertSame(1, Activity::where('log_name', 'report_export')->count());
    }

    public function test_schedule_only_columns_are_hidden_when_work_schedules_are_off(): void
    {
        $this->october();
        config(['features.work_schedules' => false]);

        $this->page()
            ->assertSee('Pola Ketepatan Waktu per Karyawan')
            ->assertSee('Terlambat Masuk')
            ->assertDontSee('Masuk Lebih Awal')
            ->assertDontSee('Lembur/Pulang Lambat')
            ->assertDontSee('Tanpa Jadwal');
    }
}
