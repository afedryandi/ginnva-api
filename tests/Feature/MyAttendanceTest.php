<?php

namespace Tests\Feature;

use App\Filament\Pages\MyAttendance;
use App\Models\Attendance;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Absen Saya": absen dari panel untuk karyawan tanpa aplikasi staff (mis. iPhone). Memakai Attendance::clockIn/clockOut yang sama dengan
 * aplikasi, jadi radius toko dan selfie wajib tetap berlaku. Waktu dibekukan: Rabu 7 Okt 2026.
 */
class MyAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('public');
        Storage::fake('local');
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('direksi', 'web');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-07 09:10:00');

        $this->store = Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true,
            'latitude' => -6.2000, 'longitude' => 106.8000, 'attendance_radius_meters' => 100, 'late_tolerance_minutes' => 15,
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

    private function staff(string $role = 'kasir', ?array $menuAccess = null, bool $withStore = true): User
    {
        return tap(User::create([
            'name' => 'Staff ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true,
            'store_id' => $withStore ? $this->store->id : null, 'join_date' => '2025-01-01', 'menu_access' => $menuAccess,
        ]), fn (User $u) => $u->assignRole($role));
    }

    private function photo(int $size = 400): UploadedFile
    {
        return UploadedFile::fake()->image('selfie.jpg', $size, $size);
    }

    public function test_access_follows_menu_access_and_attendance_exemption(): void
    {
        $this->actingAs($this->staff());
        $this->assertTrue(MyAttendance::canAccess());

        $this->actingAs($this->staff('kasir', ['BookingResource']));
        $this->assertFalse(MyAttendance::canAccess(), 'Menu tidak dicentang di Hak Akses.');

        $this->actingAs($this->staff('kasir', ['MyAttendance']));
        $this->assertTrue(MyAttendance::canAccess());

        $this->actingAs($this->staff('direksi'));
        $this->assertFalse(MyAttendance::canAccess(), 'Direksi tidak wajib absen.');
    }

    public function test_clocking_in_and_out_records_the_same_attendance_as_the_app(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->set('photo', $this->photo())
            ->call('submitClock', 'in', -6.2000, 106.8000)
            ->assertHasNoErrors()
            ->assertNotified('Absen masuk tercatat');

        $row = Attendance::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('clock', $row->entry_type);
        $this->assertNotNull($row->clock_in_at);
        $this->assertNotNull($row->clock_in_photo);
        $this->assertNull($row->clock_in_is_mocked);
        $this->assertGreaterThanOrEqual(0, (int) $row->late_minutes);
        $this->assertNotNull($row->clock_in_distance_meters);

        Carbon::setTestNow('2026-10-07 18:05:00');
        Livewire::test(MyAttendance::class)
            ->set('photo', $this->photo())
            ->call('submitClock', 'out', -6.2000, 106.8000)
            ->assertNotified('Absen keluar tercatat');

        $this->assertNotNull($row->fresh()->clock_out_at);
        $this->assertNotNull($row->fresh()->clock_out_photo);
    }

    public function test_the_store_radius_and_a_second_clock_in_are_refused(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        // Sekitar 1 km dari toko, radius toko 100 m.
        Livewire::test(MyAttendance::class)
            ->set('photo', $this->photo())
            ->call('submitClock', 'in', -6.2100, 106.8000)
            ->assertNotified('Absen ditolak');
        $this->assertSame(0, Attendance::where('user_id', $user->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Foto dihapus kalau absen ditolak.');

        Livewire::test(MyAttendance::class)->set('photo', $this->photo())->call('submitClock', 'in', -6.2000, 106.8000);
        Livewire::test(MyAttendance::class)->set('photo', $this->photo())->call('submitClock', 'in', -6.2000, 106.8000)->assertNotified('Absen ditolak');
        $this->assertSame(1, Attendance::where('user_id', $user->id)->count());
    }

    public function test_a_selfie_is_required_and_must_be_large_enough(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->call('submitClock', 'in', -6.2000, 106.8000)
            ->assertHasErrors(['photo' => 'required']);

        Livewire::test(MyAttendance::class)
            ->set('photo', $this->photo(50))
            ->call('submitClock', 'in', -6.2000, 106.8000)
            ->assertHasErrors(['photo' => 'dimensions']);

        $this->assertSame(0, Attendance::where('user_id', $user->id)->count());
    }

    public function test_invalid_coordinates_and_users_without_a_store_or_access_are_refused(): void
    {
        $user = $this->staff();
        $this->actingAs($user);

        Livewire::test(MyAttendance::class)
            ->set('photo', $this->photo())
            ->call('submitClock', 'in', 123.0, 106.8)
            ->assertNotified('Lokasi tidak valid');

        $this->actingAs($this->staff('kasir', null, false));
        Livewire::test(MyAttendance::class)
            ->set('photo', $this->photo())
            ->call('submitClock', 'in', -6.2000, 106.8000)
            ->assertNotified('Tidak bisa absen');

        $this->actingAs($this->staff('direksi'));
        Livewire::test(MyAttendance::class)->assertForbidden();
        $this->assertSame(0, Attendance::count());
        $this->assertNotNull($user);
    }
}
