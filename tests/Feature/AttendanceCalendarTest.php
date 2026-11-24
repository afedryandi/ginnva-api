<?php

namespace Tests\Feature;

use App\Filament\Pages\MyAttendance;
use App\Models\Attendance;
use App\Models\BlockedDate;
use App\Models\Store;
use App\Models\User;
use App\Services\AttendanceCalendar;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Riwayat absensi tanpa tanggal yang lompat (2026-11-24): tiap tanggal satu baris, hari tanpa catatan diberi keterangan libur / belum absen / tanpa catatan.
 */
class AttendanceCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // Rabu 14 Okt 2026.
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->store = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true,
            'opening_hours' => [
                ['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], 'open' => '09:00', 'close' => '18:00'],
                ['days' => ['sun'], 'closed' => true],
            ],
        ]);
        BlockedDate::create(['store_id' => $this->store->id, 'date' => '2026-10-12', 'reason' => 'Libur Nasional - Contoh']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(array $extra = []): User
    {
        return tap(User::create(array_merge(['name' => 'Staff ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'menu_access' => ['MyAttendance'], 'is_active' => true, 'join_date' => '2026-01-01'], $extra)), fn (User $u) => $u->assignRole('kasir'));
    }

    private function record(User $user, string $date, string $type = 'clock'): Attendance
    {
        return Attendance::withoutGlobalScopes()->create([
            'user_id' => $user->id, 'store_id' => $this->store->id, 'date' => $date, 'entry_type' => $type,
            'clock_in_at' => $type === 'clock' ? "$date 09:05:00" : null, 'clock_out_at' => $type === 'clock' ? "$date 18:00:00" : null,
            'late_minutes' => 0, 'early_leave_minutes' => 0,
        ]);
    }

    public function test_every_date_up_to_today_appears_once_newest_first(): void
    {
        $user = $this->staff();
        $this->record($user, '2026-10-13');
        $this->record($user, '2026-10-02', 'alpha');

        $days = app(AttendanceCalendar::class)->days($user, Carbon::parse('2026-10-01'));

        $this->assertCount(14, $days, '1-14 Okt: tidak ada tanggal yang lompat.');
        $this->assertSame(range(14, 1), $days->map(fn ($d) => (int) $d['date']->format('j'))->all(), 'Terbaru dulu, berurutan.');
        $byDay = $days->keyBy(fn ($d) => (int) $d['date']->format('j'));

        $this->assertSame(AttendanceCalendar::KIND_PENDING, $byDay[14]['kind'], 'Hari ini belum absen.');
        $this->assertSame(AttendanceCalendar::KIND_RECORD, $byDay[13]['kind']);
        $this->assertSame(AttendanceCalendar::KIND_HOLIDAY, $byDay[12]['kind']);
        $this->assertSame('Libur Nasional - Contoh', $byDay[12]['label'], 'Tanggal yang ditutup memakai alasannya.');
        $this->assertSame(AttendanceCalendar::KIND_HOLIDAY, $byDay[11]['kind'], 'Minggu 11 Okt: libur mingguan toko.');
        $this->assertStringContainsString('Libur mingguan toko', (string) $byDay[11]['label']);
        $this->assertSame(AttendanceCalendar::KIND_RECORD, $byDay[2]['kind']);
        $this->assertSame('alpha', $byDay[2]['attendance']->entry_type);
        $this->assertSame(AttendanceCalendar::KIND_EMPTY, $byDay[9]['kind'], 'Hari kerja lewat tanpa catatan.');
        $this->assertSame(AttendanceCalendar::KIND_HOLIDAY, $byDay[4]['kind'], 'Minggu 4 Okt.');
    }

    public function test_a_record_on_a_holiday_still_shows_the_record_and_dates_before_the_join_date_are_skipped(): void
    {
        $user = $this->staff(['join_date' => '2026-10-08']);
        $this->record($user, '2026-10-11');   // masuk di hari Minggu (libur toko)

        $days = app(AttendanceCalendar::class)->days($user, Carbon::parse('2026-10-01'));

        $this->assertSame(range(14, 8), $days->map(fn ($d) => (int) $d['date']->format('j'))->all());
        $this->assertSame(AttendanceCalendar::KIND_RECORD, $days->firstWhere(fn ($d) => $d['date']->format('j') === '11')['kind']);
    }

    public function test_past_months_are_complete_and_future_months_are_empty(): void
    {
        $user = $this->staff();
        $service = app(AttendanceCalendar::class);

        $this->assertCount(30, $service->days($user, Carbon::parse('2026-09-01')));
        $this->assertCount(0, $service->days($user, Carbon::parse('2026-11-01')));
        $this->assertCount(0, $service->days($this->staff(['join_date' => '2026-12-01']), Carbon::parse('2026-10-01')));
    }

    public function test_the_staff_api_returns_the_complete_days_next_to_the_records(): void
    {
        $user = $this->staff();
        $this->record($user, '2026-10-13');

        $response = $this->actingAs($user, 'api')->getJson('/api/staff/attendance/history?month=2026-10')->assertSuccessful();

        $this->assertCount(1, $response->json('attendances'), 'Daftar lama tetap ada.');
        $days = collect($response->json('days'));
        $this->assertCount(14, $days);
        $this->assertSame('2026-10-14', $days->first()['date']);
        $this->assertSame('belum', $days->first()['kind']);
        $this->assertSame('record', $days->firstWhere('date', '2026-10-13')['kind']);
        $this->assertSame('2026-10-13', substr($days->firstWhere('date', '2026-10-13')['attendance']['date'], 0, 10));
        $this->assertSame('libur', $days->firstWhere('date', '2026-10-12')['kind']);
        $this->assertSame('Libur Nasional - Contoh', $days->firstWhere('date', '2026-10-12')['label']);
        $this->assertNull($days->firstWhere('date', '2026-10-12')['attendance']);
    }

    public function test_the_panel_history_tab_lists_holidays_and_days_without_records(): void
    {
        $user = $this->staff();
        $this->record($user, '2026-10-13');
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->set('tab', 'riwayat')
            ->assertSee('Libur Nasional - Contoh')
            ->assertSee('Libur mingguan toko')
            ->assertSee('Belum absen')
            ->assertSee('Tanpa catatan');
    }
}
