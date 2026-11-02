<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Direksi tidak perlu absen (keputusan 2026-11-02): tidak ditandai Alpha otomatis, tidak ditolak dengan pesan membingungkan, dan
 * aplikasi diberi tahu lewat attendance_required. Role lain tetap wajib.
 */
class AttendanceExemptRoleTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['direksi', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Carbon::setTestNow('2026-11-04 09:00:00');
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $this->store->id, 'is_active' => true, 'join_date' => '2024-01-01']);
        $user->assignRole($role);

        return $user;
    }

    public function test_direksi_is_exempt_and_other_roles_are_not(): void
    {
        $this->assertTrue($this->user('direksi')->isAttendanceExempt());
        $this->assertFalse($this->user('kasir')->isAttendanceExempt());

        $ids = User::requiresAttendance()->pluck('id');
        $this->assertTrue($ids->contains(User::where('name', 'like', 'Kasir%')->value('id')));
        $this->assertFalse($ids->contains(User::where('name', 'like', 'Direksi%')->value('id')));
    }

    public function test_direksi_is_never_marked_alpha_but_other_staff_are(): void
    {
        $direksi = $this->user('direksi');
        $kasir = $this->user('kasir');

        $this->artisan('attendance:mark-absences')->assertSuccessful();

        $this->assertSame(0, Attendance::where('user_id', $direksi->id)->count(), 'Direksi tidak ditandai Alpha.');
        $this->assertSame('alpha', Attendance::where('user_id', $kasir->id)->value('entry_type'));
    }

    public function test_direksi_cannot_clock_in_and_the_app_is_told_attendance_is_not_required(): void
    {
        $direksi = $this->user('direksi');
        $kasir = $this->user('kasir');

        $this->actingAs($direksi, 'api')->postJson('/api/staff/attendance/clock-in', [])->assertStatus(403);
        $this->actingAs($direksi, 'api')->postJson('/api/staff/attendance/clock-out', [])->assertStatus(403);

        $this->actingAs($direksi, 'api')->getJson('/api/staff/attendance/today')->assertSuccessful()->assertJsonPath('attendance_required', false);
        $this->actingAs($kasir, 'api')->getJson('/api/staff/attendance/today')->assertSuccessful()->assertJsonPath('attendance_required', true);
    }
}
