<?php

namespace Tests\Feature;

use App\Filament\Widgets\NotCheckedInTodayWidget;
use App\Models\Attendance;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Mulai Wajib Absen": sebelum tanggalnya tidak ada Alpha otomatis dan karyawan tidak masuk daftar Belum Absen. Kolom kosong berarti
 * wajib sejak awal (perilaku lama). Waktu dibekukan: Rabu 7 Okt 2026 (kemarin: Selasa 6 Okt, toko buka).
 */
class AttendanceStartDateTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Carbon::setTestNow('2026-10-07 09:10:00');
        $this->store = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true,
            'opening_hours' => [
                ['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], 'open' => '09:00', 'close' => '18:00'],
                ['days' => ['sun'], 'closed' => true],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $name, ?string $startDate): User
    {
        return tap(User::create([
            'name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true,
            'store_id' => $this->store->id, 'join_date' => '2025-01-01', 'attendance_start_date' => $startDate,
        ]), fn (User $u) => $u->assignRole('kasir'));
    }

    public function test_alpha_is_only_marked_from_the_start_date(): void
    {
        $always = $this->staff('Tanpa Tanggal', null);
        $started = $this->staff('Sudah Mulai', '2026-10-06');
        $later = $this->staff('Belum Mulai', '2026-10-07');

        $this->artisan('attendance:mark-absences')->assertSuccessful();

        $marked = Attendance::where('date', '2026-10-06')->where('entry_type', 'alpha')->pluck('user_id')->all();
        $this->assertContains($always->id, $marked, 'Kolom kosong = wajib sejak awal.');
        $this->assertContains($started->id, $marked, 'Tanggal mulai sama dengan hari yang diproses ikut dihitung.');
        $this->assertNotContains($later->id, $marked, 'Belum wajib absen kemarin.');
    }

    public function test_the_not_checked_in_list_skips_people_whose_attendance_has_not_started(): void
    {
        $this->staff('Wajib Hari Ini', '2026-10-07');
        $this->staff('Wajib Besok', '2026-10-08');
        $this->staff('Wajib Lama', null);

        $names = NotCheckedInTodayWidget::pendingQuery(true, null)->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Wajib Hari Ini', 'Wajib Lama'], $names);
    }

    public function test_the_scope_compares_against_the_given_date(): void
    {
        $this->staff('A', '2026-10-10');

        $this->assertSame(0, User::where('name', 'A')->attendanceStarted('2026-10-09')->count());
        $this->assertSame(1, User::where('name', 'A')->attendanceStarted('2026-10-10')->count());
    }
    public function test_the_purge_command_only_shows_a_summary_until_applied_and_keeps_alpha_from_the_cutoff(): void
    {
        $user = $this->staff('Karyawan Baru', '2026-10-20');
        $other = $this->staff('Karyawan Lama', null);
        foreach ([['2026-10-05', $user], ['2026-10-06', $user], ['2026-10-07', $user], ['2026-10-05', $other]] as [$date, $who]) {
            Attendance::create(['user_id' => $who->id, 'store_id' => $this->store->id, 'date' => $date, 'entry_type' => 'alpha']);
        }
        Attendance::create(['user_id' => $user->id, 'store_id' => $this->store->id, 'date' => '2026-10-04', 'entry_type' => 'manual']);

        $this->artisan('attendance:purge-alpha')->assertFailed();
        $this->artisan('attendance:purge-alpha', ['--before' => '2026-10-07'])->assertSuccessful();
        $this->assertSame(4, Attendance::where('entry_type', 'alpha')->count(), 'Tanpa --apply tidak ada yang dihapus.');

        $this->artisan('attendance:purge-alpha', ['--before' => '2026-10-07', '--email' => [$user->email], '--apply' => true, '--force' => true])->assertSuccessful();

        $this->assertSame(0, Attendance::where('user_id', $user->id)->whereDate('date', '<', '2026-10-07')->where('entry_type', 'alpha')->count());
        $this->assertSame(1, Attendance::where('user_id', $user->id)->where('entry_type', 'alpha')->count(), 'Alpha pada tanggal batas tidak ikut terhapus.');
        $this->assertSame(1, Attendance::where('user_id', $other->id)->where('entry_type', 'alpha')->count(), 'Karyawan lain tidak tersentuh.');
        $this->assertSame(1, Attendance::where('user_id', $user->id)->where('entry_type', 'manual')->count(), 'Baris bukan Alpha tidak tersentuh.');
    }
}
