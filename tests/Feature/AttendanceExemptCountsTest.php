<?php

namespace Tests\Feature;

use App\Filament\Widgets\KaryawanStatsWidget;
use App\Filament\Widgets\NotCheckedInTodayWidget;
use App\Models\Attendance;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Role yang dibebaskan dari absen (Direksi) tidak boleh muncul di daftar "Belum Absen Hari Ini" maupun dihitung di kartu "Absensi Hari
 * Ini", sama dengan penanda Alpha otomatis dan pengingat belum absen.
 */
class AttendanceExemptCountsTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['kasir', 'direksi', 'partner'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name, string $role): User
    {
        return tap(User::create(['name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true, 'store_id' => $this->store->id]), fn (User $u) => $u->assignRole($role));
    }

    private function present(User $user): void
    {
        Attendance::create(['user_id' => $user->id, 'store_id' => $this->store->id, 'date' => '2026-10-07', 'entry_type' => 'manual']);
    }

    public function test_the_not_checked_in_list_skips_exempt_roles_and_those_already_recorded(): void
    {
        $this->user('Direksi A', 'direksi');
        $this->user('Partner A', 'partner');
        $this->user('Kasir Belum', 'kasir');
        $this->present($this->user('Kasir Sudah', 'kasir'));

        $this->assertSame(['Kasir Belum'], NotCheckedInTodayWidget::pendingQuery(true, null)->pluck('name')->all());
        $this->assertSame(['Kasir Belum'], NotCheckedInTodayWidget::pendingQuery(false, $this->store->id)->pluck('name')->all());
    }

    public function test_the_attendance_card_ratio_ignores_exempt_roles_in_both_numbers(): void
    {
        $this->user('Direksi A', 'direksi');
        $direksiWithRow = $this->user('Direksi B', 'direksi');
        $this->present($direksiWithRow);
        $this->present($this->user('Kasir Sudah', 'kasir'));
        $this->user('Kasir Belum', 'kasir');

        $widget = new KaryawanStatsWidget();
        $method = new \ReflectionMethod($widget, 'todayAttendanceRatio');
        $method->setAccessible(true);

        $admin = $this->user('Admin', 'kasir');
        [$present, $total] = $method->invoke($widget, $admin, true);

        // Wajib absen: Kasir Sudah, Kasir Belum, Admin. Baris absensi Direksi B tidak ikut dihitung.
        $this->assertSame(3, $total);
        $this->assertSame(1, $present);
    }
}
