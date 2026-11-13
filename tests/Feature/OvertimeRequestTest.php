<?php

namespace Tests\Feature;

use App\Exports\AttendanceSummaryExport;
use App\Filament\Pages\AttendanceReport;
use App\Filament\Resources\OvertimeRequestResource\Pages\ListOvertimeRequests;
use App\Models\Attendance;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\AttendanceAudience;
use App\Services\OvertimeRequestService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pengajuan Lembur (2026-11-12): lembur wajib diajukan sebelum dimulai dengan pekerjaan yang jelas, disetujui store manager toko itu atau akses
 * penuh, dengan batas 4 jam per hari dan 18 jam per minggu. Hanya jam yang disetujui diakui. Toko buka Senin-Sabtu 09:00-18:00, Minggu libur.
 * "Sekarang" dibekukan Kamis 12 November 2026 pukul 10:00.
 */
class OvertimeRequestTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private User $andi;
    private User $manager;
    private User $otherManager;
    private User $admin;
    private OvertimeRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'store_manager', 'super_admin', 'direksi'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-11-12 10:00:00');

        $this->store = $this->store('Toko A');
        $this->otherStore = $this->store('Toko B');
        $this->andi = $this->user('kasir', $this->store, 'Andi');
        $this->manager = $this->user('store_manager', $this->store, 'Manajer A');
        $this->otherManager = $this->user('store_manager', $this->otherStore, 'Manajer B');
        $this->admin = $this->user('super_admin', null, 'Admin');
        $this->service = app(OvertimeRequestService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function store(string $name): Store
    {
        return Store::create([
            'city' => 'Jakarta', 'address' => 'Jl. A', 'name' => $name, 'is_active' => true,
            'opening_hours' => [
                ['days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], 'open' => '09:00', 'close' => '18:00'],
                ['days' => ['sun'], 'closed' => true],
            ],
        ]);
    }

    private function user(string $role, ?Store $store, string $name): User
    {
        return tap(User::create(['name' => $name, 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $store?->id, 'join_date' => '2025-01-01', 'is_active' => true]), fn (User $u) => $u->assignRole($role));
    }

    private function submit(array $override = [], ?User $as = null): OvertimeRequest
    {
        return $this->service->submit($as ?? $this->andi, $override + [
            'starts_at' => '2026-11-12 18:30', 'ends_at' => '2026-11-12 20:30',
            'description' => 'Menyelesaikan pemasangan PPF mobil pelanggan yang tertunda',
        ]);
    }

    private function seedRequest(User $user, string $date, string $from, string $to, string $status = 'approved'): OvertimeRequest
    {
        $start = Carbon::parse("{$date} {$from}");
        $end = Carbon::parse("{$date} {$to}");

        return OvertimeRequest::create([
            'user_id' => $user->id, 'store_id' => $user->store_id, 'date' => $date, 'starts_at' => $start, 'ends_at' => $end,
            'minutes' => (int) $start->diffInMinutes($end), 'description' => 'Pekerjaan terjadwal', 'status' => $status,
        ]);
    }

    private function booking(Store $store, string $number, string $status = 'confirmed'): Booking
    {
        $customer = Customer::create(['name' => 'Pelanggan ' . $number, 'phone_number' => '0812' . random_int(1000000, 9999999)]);

        return Booking::create([
            'booking_number' => $number, 'customer_id' => $customer->id, 'store_id' => $store->id, 'service_type' => 'Pelindung Cat (PPF)',
            'product_ppf' => true, 'preferred_date' => '2026-11-12', 'status' => $status,
        ]);
    }

    private function assertRejected(callable $submit, string $contains): void
    {
        try {
            $submit();
            $this->fail('Seharusnya ditolak: ' . $contains);
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    // ------------------------------------------------------------- pengajuan

    public function test_a_valid_request_is_created_pending_and_tells_the_deciders(): void
    {
        $request = $this->submit();

        $this->assertSame('pending', $request->status);
        $this->assertSame(120, $request->minutes);
        $this->assertSame('2026-11-12', $request->date->toDateString());
        $this->assertNotEmpty($this->manager->notifications()->get()->where('data.title', 'Pengajuan Lembur Baru'), 'Store manager toko itu diberi tahu.');
        $this->assertNotEmpty($this->admin->notifications()->get()->where('data.title', 'Pengajuan Lembur Baru'), 'Akses penuh diberi tahu.');
        $this->assertSame([], $this->otherManager->notifications()->get()->all(), 'Store manager toko lain tidak diganggu.');
        $this->assertSame([], $this->andi->notifications()->get()->all(), 'Pemohon tidak diberi tahu soal pengajuannya sendiri.');
    }

    public function test_it_must_be_submitted_before_it_starts(): void
    {
        $this->assertRejected(fn () => $this->submit(['starts_at' => '2026-11-12 09:00', 'ends_at' => '2026-11-12 11:00']), 'sebelum dimulai');
        $this->assertRejected(fn () => $this->submit(['starts_at' => '2026-11-11 18:30', 'ends_at' => '2026-11-11 20:00']), 'sebelum dimulai');
        $this->assertRejected(fn () => $this->submit(['starts_at' => '2026-12-20 18:30', 'ends_at' => '2026-12-20 20:00']), 'hari ke depan');
    }

    public function test_time_rules(): void
    {
        $this->assertRejected(fn () => $this->submit(['starts_at' => '2026-11-12 19:00', 'ends_at' => '2026-11-12 18:30']), 'setelah jam mulai');
        $this->assertRejected(fn () => $this->submit(['starts_at' => '2026-11-12 19:00', 'ends_at' => '2026-11-13 01:00']), 'hari yang sama');
        $this->assertRejected(fn () => $this->submit(['starts_at' => '2026-11-12 19:00', 'ends_at' => '2026-11-12 19:20']), 'minimal 30 menit');
    }

    public function test_the_work_must_be_clear_a_booking_or_a_description(): void
    {
        $this->assertRejected(fn () => $this->submit(['description' => null]), 'Jelaskan pekerjaan');
        $this->assertRejected(fn () => $this->submit(['description' => '   ']), 'Jelaskan pekerjaan');

        $booking = $this->booking($this->store, 'BKG-OT-1', 'confirmed');

        $request = $this->submit(['description' => null, 'booking_id' => $booking->id]);
        $this->assertSame($booking->id, $request->booking_id, 'Booking terkait saja sudah cukup sebagai pekerjaan yang jelas.');
        $this->assertStringContainsString('BKG-OT-1', $request->fresh()->load('booking')->workLabel());
    }

    public function test_a_booking_from_another_store_is_refused(): void
    {
        $booking = $this->booking($this->otherStore, 'BKG-OT-2', 'confirmed');

        $this->assertRejected(fn () => $this->submit(['booking_id' => $booking->id]), 'tidak ditemukan di toko Anda');
    }

    public function test_it_refuses_overlaps_and_leave_days_and_exempt_accounts(): void
    {
        $this->submit();
        $this->assertRejected(fn () => $this->submit(['starts_at' => '2026-11-12 20:00', 'ends_at' => '2026-11-12 21:00']), 'bertabrakan');

        LeaveRequest::create(['user_id' => $this->andi->id, 'store_id' => $this->store->id, 'type' => 'izin', 'start_date' => '2026-11-13', 'end_date' => '2026-11-13', 'reason' => 'x', 'status' => 'approved']);
        $this->assertRejected(fn () => $this->submit(['starts_at' => '2026-11-13 18:30', 'ends_at' => '2026-11-13 20:00']), 'izin/cuti');

        $director = $this->user('direksi', $this->store, 'Direktur');
        $this->assertRejected(fn () => $this->submit([], $director), 'tidak wajib absen');

        $noStore = $this->user('kasir', null, 'Tanpa Toko');
        $this->assertRejected(fn () => $this->submit([], $noStore), 'belum terhubung ke toko');
    }

    public function test_daily_cap_is_four_hours(): void
    {
        $this->seedRequest($this->andi, '2026-11-14', '18:00', '21:00'); // 3 jam sudah disetujui

        $this->assertRejected(fn () => $this->submit(['starts_at' => '2026-11-14 21:00', 'ends_at' => '2026-11-14 22:30']), 'per hari');

        $ok = $this->submit(['starts_at' => '2026-11-14 21:00', 'ends_at' => '2026-11-14 22:00']);
        $this->assertSame(60, $ok->minutes, 'Tepat 4 jam per hari masih boleh.');
    }

    public function test_weekly_cap_is_eighteen_hours(): void
    {
        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12'] as $date) {
            $this->seedRequest($this->andi, $date, '18:00', '22:00'); // 16 jam pada minggu itu
        }

        $this->assertRejected(fn () => $this->submit(['starts_at' => '2026-11-13 18:00', 'ends_at' => '2026-11-13 20:30']), 'per minggu');

        $ok = $this->submit(['starts_at' => '2026-11-13 18:00', 'ends_at' => '2026-11-13 20:00']);
        $this->assertSame(120, $ok->minutes, 'Tepat 18 jam per minggu masih boleh.');
    }

    // ------------------------------------------------------------- keputusan

    public function test_the_store_manager_of_that_store_and_full_access_may_approve(): void
    {
        $first = $this->submit();
        $this->service->approve($first, $this->manager, 'Silakan');
        $this->assertSame('approved', $first->fresh()->status);
        $this->assertSame($this->manager->id, $first->fresh()->reviewed_by);
        $this->assertNotEmpty($this->andi->notifications()->get()->where('data.title', 'Pengajuan Lembur Disetujui'));

        $second = $this->submit(['starts_at' => '2026-11-13 18:30', 'ends_at' => '2026-11-13 20:00']);
        $this->service->approve($second, $this->admin);
        $this->assertSame('approved', $second->fresh()->status);
    }

    public function test_others_may_not_decide_and_nobody_decides_their_own_request(): void
    {
        $request = $this->submit();

        $this->assertRejected(fn () => $this->service->approve($request, $this->otherManager), 'Hanya akses penuh atau store manager toko ini');
        $this->assertRejected(fn () => $this->service->approve($request, $this->andi), 'milik sendiri');

        $managerOwn = $this->submit([], $this->manager);
        $this->assertRejected(fn () => $this->service->approve($managerOwn, $this->manager), 'milik sendiri');
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_rejection_needs_a_reason_and_notifies_the_requester(): void
    {
        $request = $this->submit();

        $this->assertRejected(fn () => $this->service->reject($request, $this->manager, '  '), 'Alasan penolakan wajib');

        $this->service->reject($request, $this->manager, 'Belum ada pekerjaan mendesak');
        $this->assertSame('rejected', $request->fresh()->status);
        $body = $this->andi->notifications()->get()->firstWhere('data.title', 'Pengajuan Lembur Ditolak')->data['body'];
        $this->assertStringContainsString('Belum ada pekerjaan mendesak', $body);
    }

    public function test_a_decided_or_expired_request_cannot_be_decided_again(): void
    {
        $request = $this->submit();
        $this->service->approve($request, $this->manager);
        $this->assertRejected(fn () => $this->service->reject($request, $this->admin, 'x'), 'sudah diputuskan');

        $late = $this->submit(['starts_at' => '2026-11-13 18:30', 'ends_at' => '2026-11-13 20:00']);
        Carbon::setTestNow('2026-11-13 21:00:00');
        $this->assertRejected(fn () => $this->service->approve($late, $this->manager), 'sudah lewat');
    }

    public function test_cancel_rules(): void
    {
        $pending = $this->submit();
        $this->assertRejected(fn () => $this->service->cancel($pending, $this->manager->id), 'bukan milik Anda');
        $this->service->cancel($pending, $this->andi->id);
        $this->assertSame('cancelled', $pending->fresh()->status);

        $approved = $this->submit(['starts_at' => '2026-11-13 18:30', 'ends_at' => '2026-11-13 20:00']);
        $this->service->approve($approved, $this->manager);
        $this->service->cancel($approved, $this->andi->id); // disetujui tetapi belum dimulai: boleh
        $this->assertSame('cancelled', $approved->fresh()->status);

        $started = $this->submit(['starts_at' => '2026-11-14 18:30', 'ends_at' => '2026-11-14 20:00']);
        $this->service->approve($started, $this->manager);
        Carbon::setTestNow('2026-11-14 19:00:00');
        $this->assertRejected(fn () => $this->service->cancel($started, $this->andi->id), 'sudah dimulai');
    }

    // ------------------------------------------------------------- jam yang diakui

    private function attendance(string $date, string $in, string $out): Attendance
    {
        return Attendance::create([
            'user_id' => $this->andi->id, 'store_id' => $this->store->id, 'date' => $date, 'entry_type' => 'clock',
            'clock_in_at' => "{$date} {$in}", 'clock_out_at' => "{$date} {$out}",
        ]);
    }

    public function test_only_approved_hours_are_recognized_as_overtime(): void
    {
        // Disetujui 18:00-20:00, bekerja 09:00-20:30: lembur yang diakui 120 menit; 20:00-20:30 (30 menit) tanpa persetujuan.
        $approved = $this->seedRequest($this->andi, '2026-11-12', '18:00', '20:00');
        $attendance = $this->attendance('2026-11-12', '09:00', '20:30');
        $list = collect([$approved]);

        $this->assertSame(120, $this->service->recognizedMinutes($attendance, $list));
        $this->assertSame(30, $this->service->unapprovedExtraMinutes($attendance, $list, false, '18:00'));
    }

    public function test_unapproved_work_after_closing_is_flagged_but_a_few_minutes_are_ignored(): void
    {
        $none = collect();

        $this->assertSame(0, $this->service->unapprovedExtraMinutes($this->attendance('2026-11-10', '09:00', '18:10'), $none, false, '18:00'), 'Pulang 10 menit setelah tutup wajar.');
        $this->assertSame(90, $this->service->unapprovedExtraMinutes($this->attendance('2026-11-11', '09:00', '19:30'), $none, false, '18:00'));
        $this->assertSame(0, $this->service->recognizedMinutes($this->attendance('2026-11-09', '09:00', '19:30'), $none), 'Tanpa persetujuan tidak ada lembur diakui.');
    }

    public function test_work_on_a_closed_day_needs_approval_too(): void
    {
        $sunday = $this->attendance('2026-11-15', '10:00', '14:00'); // Minggu, toko libur

        $this->assertSame(240, $this->service->unapprovedExtraMinutes($sunday, collect(), true, null));

        $approved = $this->seedRequest($this->andi, '2026-11-15', '10:00', '14:00');
        $this->assertSame(240, $this->service->recognizedMinutes($sunday, collect([$approved])));
        $this->assertSame(0, $this->service->unapprovedExtraMinutes($sunday, collect([$approved]), true, null));
    }

    public function test_an_open_attendance_row_has_no_recognized_overtime_yet(): void
    {
        $open = Attendance::create(['user_id' => $this->andi->id, 'store_id' => $this->store->id, 'date' => '2026-11-12', 'entry_type' => 'clock', 'clock_in_at' => '2026-11-12 09:00:00']);

        $this->assertSame(0, $this->service->recognizedMinutes($open, collect([$this->seedRequest($this->andi, '2026-11-12', '18:00', '20:00')])));
    }

    // ------------------------------------------------------------- pengingat absen pulang

    public function test_the_end_time_follows_approved_overtime(): void
    {
        $audience = app(AttendanceAudience::class);
        $day = Carbon::parse('2026-11-12');

        $this->assertSame('18:00', substr((string) $audience->endTime($this->andi->id, $this->store, $day), 0, 5), 'Tanpa lembur: jam tutup toko.');

        $this->seedRequest($this->andi, '2026-11-12', '18:00', '20:30');
        $this->assertSame('20:30', substr((string) $audience->endTime($this->andi->id, $this->store, $day), 0, 5), 'Dengan lembur disetujui: jam selesai lembur.');

        $this->seedRequest($this->andi, '2026-11-15', '10:00', '13:00'); // Minggu, toko libur
        $this->assertSame('13:00', substr((string) $audience->endTime($this->andi->id, $this->store, Carbon::parse('2026-11-15')), 0, 5));
        $this->assertNull($audience->endTime($this->manager->id, $this->store, Carbon::parse('2026-11-15')), 'Toko libur dan tanpa lembur: tidak ada jam selesai.');
    }

    public function test_the_clockout_reminder_waits_for_the_overtime_to_end(): void
    {
        $this->seedRequest($this->andi, '2026-11-12', '18:00', '20:30');
        Attendance::create(['user_id' => $this->andi->id, 'store_id' => $this->store->id, 'date' => '2026-11-12', 'entry_type' => 'clock', 'clock_in_at' => '2026-11-12 09:00:00']);

        Carbon::setTestNow('2026-11-12 18:10:00'); // setelah jam tutup, tetapi lembur masih berjalan
        $this->artisan('attendance:remind-clockouts')->assertSuccessful();
        $this->assertSame([], $this->andi->notifications()->get()->all(), 'Belum diingatkan selama lembur berjalan.');

        Carbon::setTestNow('2026-11-12 20:36:00'); // 20:30 + 5 menit
        $this->artisan('attendance:remind-clockouts')->assertSuccessful();
        $this->assertNotEmpty($this->andi->notifications()->get()->where('data.title', 'Jangan Lupa Absen Pulang'));
    }

    // ------------------------------------------------------------- API aplikasi staf

    public function test_the_app_can_submit_list_and_cancel(): void
    {
        $this->actingAs($this->andi, 'api');

        $response = $this->postJson('/api/staff/overtime-requests', [
            'date' => '2026-11-12', 'start_time' => '18:30', 'end_time' => '20:30',
            'description' => 'Menyelesaikan pemasangan PPF mobil pelanggan yang tertunda',
        ]);
        $response->assertCreated()->assertJsonPath('overtime_request.status', 'pending')->assertJsonPath('overtime_request.minutes', 120);
        $id = $response->json('overtime_request.id');

        $this->getJson('/api/staff/overtime-requests')
            ->assertOk()
            ->assertJsonPath('limits.max_hours_per_day', 4)
            ->assertJsonPath('limits.max_hours_per_week', 18)
            ->assertJsonCount(1, 'overtime_requests');

        $this->postJson("/api/staff/overtime-requests/{$id}/cancel")->assertOk();
        $this->assertSame('cancelled', OvertimeRequest::withoutGlobalScopes()->find($id)->status);
    }

    public function test_the_app_shows_a_clear_error_for_rule_violations_and_hides_others_requests(): void
    {
        $this->actingAs($this->andi, 'api');

        $this->postJson('/api/staff/overtime-requests', ['date' => '2026-11-12', 'start_time' => '18:30', 'end_time' => '20:30', 'description' => 'lembur'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->postJson('/api/staff/overtime-requests', ['date' => '2026-11-12', 'start_time' => '8.30', 'end_time' => '20:30'])->assertStatus(422);

        $others = $this->seedRequest($this->manager, '2026-11-12', '18:00', '19:00', 'pending');
        $this->getJson('/api/staff/overtime-requests')->assertJsonCount(0, 'overtime_requests');
        $this->postJson("/api/staff/overtime-requests/{$others->id}/cancel")->assertStatus(404);
    }

    public function test_the_booking_picker_lists_only_active_bookings_of_the_own_store(): void
    {
        $mine = $this->booking($this->store, 'BKG-MINE', 'confirmed');
        $this->booking($this->store, 'BKG-DONE', 'completed');
        $this->booking($this->otherStore, 'BKG-OTHER', 'confirmed');

        $this->actingAs($this->andi, 'api')
            ->getJson('/api/staff/overtime-requests/bookings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_today_lists_the_overtime_of_the_day(): void
    {
        $this->seedRequest($this->andi, '2026-11-12', '18:00', '20:00');
        $this->seedRequest($this->andi, '2026-11-13', '18:00', '20:00');

        $this->actingAs($this->andi, 'api')
            ->getJson('/api/staff/attendance/today')
            ->assertOk()
            ->assertJsonCount(1, 'overtime')
            ->assertJsonPath('overtime.0.status', 'approved');
    }

    // ------------------------------------------------------------- rekap absensi

    public function test_the_attendance_recap_shows_recognized_and_unapproved_overtime_hours(): void
    {
        $this->seedRequest($this->andi, '2026-11-12', '18:00', '20:00');
        $this->attendance('2026-11-12', '09:00', '20:30'); // 120 menit diakui, 30 menit tanpa persetujuan
        $this->attendance('2026-11-11', '09:00', '19:30'); // 90 menit tanpa persetujuan

        $this->actingAs($this->admin, 'web');
        $page = Livewire::test(AttendanceReport::class)->instance();
        $rows = $page->summaryByEmployee($page->getResult()['rows']);
        $andi = collect($rows)->firstWhere('name', 'Andi');

        $this->assertSame([2.0, 2.0], [$andi['overtime_hours'], $andi['unapproved_hours']]);

        $export = new AttendanceSummaryExport($rows, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $headings = $export->headings();
        $this->assertContains('Jam Lembur Disetujui', $headings);
        $this->assertContains('Jam Luar Jam Resmi Tanpa Persetujuan', $headings);
    }

    // ------------------------------------------------------------- panel

    public function test_the_panel_lets_the_decider_approve_but_not_the_requester(): void
    {
        $request = $this->submit();

        $this->actingAs($this->andi, 'web');
        Livewire::test(ListOvertimeRequests::class)
            ->assertTableActionHidden('approve', $request)
            ->assertTableActionVisible('cancel', $request);

        $this->actingAs($this->admin, 'web');
        Livewire::test(ListOvertimeRequests::class)
            ->assertTableActionVisible('approve', $request)
            ->callTableAction('approve', $request, ['review_notes' => 'Setuju'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('approved', $request->fresh()->status);
    }
}
