<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\AttendanceCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Audit Majoo f24 ("Alur approval utk absensi anomali"), dibangun
 * 2026-09-22 atas keputusan user. Fokus test: submit membuat baris
 * pending (data absensi TIDAK berubah dulu), approve baru menerapkan
 * perubahan, reject tidak menyentuh data absensi sama sekali, dan
 * permintaan yang sudah diputuskan tidak bisa diputuskan ulang.
 */
class AttendanceCorrectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeStoreAndUser(): array
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. Test 1', 'name' => 'Toko Test', 'is_active' => true]);
        $user = User::create(['name' => 'Staff A', 'email' => 'staff-a@test.local', 'password' => 'x', 'store_id' => $store->id]);

        return [$store, $user];
    }

    public function test_submit_creates_pending_request_without_changing_attendance(): void
    {
        [$store, $user] = $this->makeStoreAndUser();

        $request = app(AttendanceCorrectionService::class)->submit([
            'attendance_id' => null,
            'user_id' => $user->id,
            'store_id' => $store->id,
            'date' => '2026-09-20',
            'entry_type' => 'manual',
            'clock_in_at' => '2026-09-20 08:00:00',
            'clock_out_at' => '2026-09-20 17:00:00',
            'reason' => 'Device absen mati',
        ], $user->id);

        $this->assertEquals(AttendanceCorrectionRequest::STATUS_PENDING, $request->status);
        $this->assertDatabaseMissing('attendances', ['user_id' => $user->id, 'date' => '2026-09-20']);
    }

    public function test_approve_creates_attendance_and_marks_request_approved(): void
    {
        [$store, $user] = $this->makeStoreAndUser();
        $manager = User::create(['name' => 'Manager', 'email' => 'mgr@test.local', 'password' => 'x', 'store_id' => $store->id]);

        $service = app(AttendanceCorrectionService::class);
        $request = $service->submit([
            'attendance_id' => null,
            'user_id' => $user->id,
            'store_id' => $store->id,
            'date' => '2026-09-20',
            'entry_type' => 'manual',
            'clock_in_at' => '2026-09-20 08:00:00',
            'clock_out_at' => '2026-09-20 17:00:00',
            'reason' => 'Device absen mati',
        ], $user->id);

        $attendance = $service->approve($request, $manager->id, 'Sudah dikonfirmasi ke toko.');

        $this->assertInstanceOf(Attendance::class, $attendance);
        $this->assertEquals('manual', $attendance->entry_type);

        $request->refresh();
        $this->assertEquals(AttendanceCorrectionRequest::STATUS_APPROVED, $request->status);
        $this->assertEquals($manager->id, $request->reviewed_by);
        $this->assertEquals($attendance->id, $request->attendance_id);
    }

    public function test_reject_does_not_create_attendance(): void
    {
        [$store, $user] = $this->makeStoreAndUser();
        $manager = User::create(['name' => 'Manager', 'email' => 'mgr2@test.local', 'password' => 'x', 'store_id' => $store->id]);

        $service = app(AttendanceCorrectionService::class);
        $request = $service->submit([
            'attendance_id' => null,
            'user_id' => $user->id,
            'store_id' => $store->id,
            'date' => '2026-09-21',
            'entry_type' => 'manual',
            'clock_in_at' => '2026-09-21 08:00:00',
            'clock_out_at' => null,
            'reason' => 'Lupa absen',
        ], $user->id);

        $service->reject($request, $manager->id, 'Tidak ada bukti pendukung.');

        $request->refresh();
        $this->assertEquals(AttendanceCorrectionRequest::STATUS_REJECTED, $request->status);
        $this->assertDatabaseMissing('attendances', ['user_id' => $user->id, 'date' => '2026-09-21']);
    }

    public function test_cannot_decide_an_already_decided_request(): void
    {
        [$store, $user] = $this->makeStoreAndUser();
        $manager = User::create(['name' => 'Manager', 'email' => 'mgr3@test.local', 'password' => 'x', 'store_id' => $store->id]);

        $service = app(AttendanceCorrectionService::class);
        $request = $service->submit([
            'attendance_id' => null,
            'user_id' => $user->id,
            'store_id' => $store->id,
            'date' => '2026-09-22',
            'entry_type' => 'manual',
            'clock_in_at' => '2026-09-22 08:00:00',
            'clock_out_at' => null,
            'reason' => 'Test',
        ], $user->id);

        $service->approve($request, $manager->id);

        $this->expectException(RuntimeException::class);
        $service->reject($request->fresh(), $manager->id, 'Terlambat menolak.');
    }

    private function payload(User $user, Store $store, array $overrides = []): array
    {
        return $overrides + [
            'attendance_id' => null, 'user_id' => $user->id, 'store_id' => $store->id, 'date' => '2026-09-20', 'entry_type' => 'manual',
            'clock_in_at' => '2026-09-20 08:00:00', 'clock_out_at' => '2026-09-20 17:00:00', 'reason' => 'Lupa absen',
        ];
    }

    public function test_submit_rejects_invalid_requests_with_the_same_rules_for_app_and_panel(): void
    {
        [$store, $user] = $this->makeStoreAndUser();
        $service = app(AttendanceCorrectionService::class);

        $cases = [
            'tanpa jam' => [['clock_in_at' => null, 'clock_out_at' => null], 'Isi minimal salah satu'],
            'keluar sebelum masuk' => [['clock_in_at' => '2026-09-20 17:00:00', 'clock_out_at' => '2026-09-20 08:00:00'], 'Jam keluar harus setelah jam masuk'],
            'beda tanggal' => [['clock_in_at' => '2026-09-19 08:00:00', 'clock_out_at' => null], 'tanggal yang sama'],
        ];

        foreach ($cases as $label => [$overrides, $message]) {
            try {
                $service->submit($this->payload($user, $store, $overrides), $user->id);
                $this->fail("Seharusnya ditolak: {$label}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $label);
            }
        }

        $this->assertSame(0, AttendanceCorrectionRequest::count());

        $service->submit($this->payload($user, $store), $user->id);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('masih menunggu keputusan');
        $service->submit($this->payload($user, $store, ['clock_in_at' => '2026-09-20 09:00:00']), $user->id);
    }

    public function test_approving_keeps_the_recorded_time_the_request_left_blank(): void
    {
        [$store, $user] = $this->makeStoreAndUser();
        $manager = User::create(['name' => 'Manager', 'email' => 'mgr-keep@test.local', 'password' => 'x', 'store_id' => $store->id]);
        Attendance::create([
            'user_id' => $user->id, 'store_id' => $store->id, 'date' => '2026-09-20', 'entry_type' => 'clock', 'clock_in_at' => '2026-09-20 09:00:00',
        ]);

        $service = app(AttendanceCorrectionService::class);
        $request = $service->submit($this->payload($user, $store, ['clock_in_at' => null, 'clock_out_at' => '2026-09-20 18:00:00']), $user->id);
        $attendance = $service->approve($request, $manager->id);

        $this->assertSame('09:00', $attendance->fresh()->clock_in_at->format('H:i'), 'Jam masuk yang sudah ada tidak boleh hilang.');
        $this->assertSame('18:00', $attendance->fresh()->clock_out_at->format('H:i'));
    }

    public function test_approving_refuses_a_clock_out_before_the_recorded_clock_in(): void
    {
        [$store, $user] = $this->makeStoreAndUser();
        $manager = User::create(['name' => 'Manager', 'email' => 'mgr-bad@test.local', 'password' => 'x', 'store_id' => $store->id]);
        Attendance::create([
            'user_id' => $user->id, 'store_id' => $store->id, 'date' => '2026-09-20', 'entry_type' => 'clock', 'clock_in_at' => '2026-09-20 15:00:00',
        ]);

        $service = app(AttendanceCorrectionService::class);
        $request = $service->submit($this->payload($user, $store, ['clock_in_at' => null, 'clock_out_at' => '2026-09-20 10:00:00']), $user->id);

        $this->expectException(RuntimeException::class);
        $service->approve($request, $manager->id);
    }

    public function test_only_the_requester_can_cancel_and_only_while_pending(): void
    {
        [$store, $user] = $this->makeStoreAndUser();
        $manager = User::create(['name' => 'Manager', 'email' => 'mgr-cancel@test.local', 'password' => 'x', 'store_id' => $store->id]);
        $service = app(AttendanceCorrectionService::class);
        $request = $service->submit($this->payload($user, $store), $user->id);

        try {
            $service->cancel($request, $manager->id);
            $this->fail('Bukan pengaju, seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('bukan milik Anda', $e->getMessage());
        }

        $service->cancel($request, $user->id);
        $this->assertSame(AttendanceCorrectionRequest::STATUS_CANCELLED, $request->fresh()->status);

        $this->expectException(RuntimeException::class);
        $service->approve($request->fresh(), $manager->id);
    }
}
