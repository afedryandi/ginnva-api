<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\PartialLeaveRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\ApprovalCenterService;
use App\Services\PartialLeaveRequestService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pengajuan Izin Sebagian Hari (2026-11-18): tiga kategori (Terlambat Masuk Kerja, Keluar Lingkungan Kantor, Pulang Cepat), tidak memotong apa pun, boleh
 * susulan 3 hari. Setelah disetujui, menit telat atau pulang cepat yang tercakup tidak dihitung. "Sekarang" dibekukan Kamis 12 November 2026 pukul 10:00.
 */
class PartialLeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private User $andi;
    private User $manager;
    private User $otherManager;
    private User $admin;
    private PartialLeaveRequestService $service;

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
        $this->service = app(PartialLeaveRequestService::class);
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

    private function late(array $override = [], ?User $as = null): PartialLeaveRequest
    {
        return $this->service->submit($as ?? $this->andi, $override + [
            'date' => '2026-11-12', 'category' => 'late_arrival', 'time' => '10:30', 'reason' => 'Mengantar anak ke sekolah',
        ]);
    }

    private function early(array $override = []): PartialLeaveRequest
    {
        return $this->service->submit($this->andi, $override + [
            'date' => '2026-11-12', 'category' => 'early_leave', 'time' => '15:00', 'reason' => 'Kontrol ke dokter',
        ]);
    }

    private function out(array $override = []): PartialLeaveRequest
    {
        return $this->service->submit($this->andi, $override + [
            'date' => '2026-11-12', 'category' => 'leave_office', 'start_time' => '12:00', 'end_time' => '14:00', 'reason' => 'Mengurus dokumen di bank',
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

    private function clockRow(string $date, string $in, ?string $out = null, int $late = 0, int $early = 0): Attendance
    {
        return Attendance::create([
            'user_id' => $this->andi->id, 'store_id' => $this->store->id, 'date' => $date, 'entry_type' => 'clock',
            'clock_in_at' => "{$date} {$in}", 'clock_out_at' => $out ? "{$date} {$out}" : null, 'late_minutes' => $late, 'early_leave_minutes' => $early,
        ]);
    }

    // ------------------------------------------------------------- pengajuan

    public function test_a_valid_request_is_created_pending_and_tells_the_deciders(): void
    {
        $request = $this->late();

        $this->assertSame('pending', $request->status);
        $this->assertSame('Terlambat Masuk Kerja', $request->categoryLabel());
        $this->assertNotEmpty($this->manager->notifications()->get()->where('data.title', 'Pengajuan Izin Sebagian Hari Baru'), 'Store manager toko itu diberi tahu.');
        $this->assertNotEmpty($this->admin->notifications()->get()->where('data.title', 'Pengajuan Izin Sebagian Hari Baru'), 'Akses penuh diberi tahu.');
        $this->assertSame([], $this->otherManager->notifications()->get()->all(), 'Store manager toko lain tidak diganggu.');
        $this->assertSame([], $this->andi->notifications()->get()->all(), 'Pemohon tidak diberi tahu soal pengajuannya sendiri.');
    }

    public function test_each_category_stores_its_own_window(): void
    {
        $late = $this->late();
        $this->assertSame(['00:00:00', '10:30:00'], [$late->start_time, $late->end_time]);
        $this->assertSame('Masuk paling lambat 10:30', $late->windowLabel());

        $early = $this->early();
        $this->assertSame(['15:00:00', '23:59:00'], [$early->start_time, $early->end_time]);
        $this->assertSame('Pulang mulai 15:00', $early->windowLabel());

        $out = $this->out();
        $this->assertSame(['12:00:00', '14:00:00'], [$out->start_time, $out->end_time]);
        $this->assertSame('12:00–14:00', $out->windowLabel());
    }

    public function test_the_category_and_times_are_validated(): void
    {
        $this->assertRejected(fn () => $this->late(['category' => 'lainnya']), 'Pilih kategori');
        $this->assertRejected(fn () => $this->late(['time' => null]), 'Isi jam paling lambat');
        $this->assertRejected(fn () => $this->early(['time' => '']), 'Isi jam Anda mulai pulang');
        $this->assertRejected(fn () => $this->late(['time' => '25:00']), 'Format jam');
        $this->assertRejected(fn () => $this->out(['start_time' => null]), 'Isi jam keluar');
        $this->assertRejected(fn () => $this->out(['end_time' => '11:00']), 'setelah jam keluar');
    }

    public function test_the_reason_is_required(): void
    {
        $this->assertRejected(fn () => $this->late(['reason' => '']), 'alasan');
        $this->assertRejected(fn () => $this->late(['reason' => '   ']), 'alasan');
    }

    public function test_it_can_be_filed_after_the_fact_for_three_days_only(): void
    {
        $this->assertSame('2026-11-09', $this->late(['date' => '2026-11-09'])->date->toDateString(), '3 hari ke belakang masih boleh.');

        $this->assertRejected(fn () => $this->early(['date' => '2026-11-08']), 'paling lama 3 hari');
        $this->assertRejected(fn () => $this->late(['date' => '2026-11-27']), '14 hari ke depan');
    }

    public function test_one_request_per_category_per_day_but_categories_can_combine(): void
    {
        $this->late();
        $this->assertRejected(fn () => $this->late(), 'Sudah ada pengajuan Terlambat Masuk Kerja');

        $this->assertSame('pending', $this->early()->status, 'Kategori lain di hari yang sama boleh.');
        $this->assertSame('pending', $this->out()->status);
        $this->assertSame('pending', $this->out(['start_time' => '15:00', 'end_time' => '16:00'])->status, 'Keluar kantor beberapa kali selama jamnya tidak tumpang tindih.');
        $this->assertRejected(fn () => $this->out(['start_time' => '13:00', 'end_time' => '15:30']), 'bertabrakan');
    }

    public function test_not_on_a_full_day_leave_field_duty_or_leave_row(): void
    {
        LeaveRequest::create(['user_id' => $this->andi->id, 'store_id' => $this->store->id, 'type' => 'izin', 'start_date' => '2026-11-13', 'end_date' => '2026-11-13', 'reason' => 'x', 'status' => 'approved']);
        $this->assertRejected(fn () => $this->late(['date' => '2026-11-13']), 'izin/cuti sehari penuh');

        Attendance::create(['user_id' => $this->andi->id, 'store_id' => $this->store->id, 'date' => '2026-11-14', 'entry_type' => 'field_duty']);
        $this->assertRejected(fn () => $this->late(['date' => '2026-11-14']), 'Dinas Luar sehari penuh');
    }

    // ------------------------------------------------------------- hitungan absensi

    public function test_an_approved_late_arrival_removes_the_covered_late_minutes(): void
    {
        // Toko buka 09:00, absen masuk 11:30 = 150 menit telat; izin masuk paling lambat 10:30 menutup 90 menit.
        $row = $this->clockRow('2026-11-11', '11:30', null, 150);
        $request = $this->late(['date' => '2026-11-11', 'time' => '10:30']);

        $this->service->approve($request, $this->manager);

        $this->assertSame(60, $row->fresh()->late_minutes);
    }

    public function test_arriving_before_the_allowed_time_leaves_no_late_minutes(): void
    {
        $row = $this->clockRow('2026-11-11', '10:00', null, 60);
        $request = $this->late(['date' => '2026-11-11', 'time' => '10:30']);

        $this->service->approve($request, $this->manager);

        $this->assertSame(0, $row->fresh()->late_minutes);
    }

    public function test_an_approved_early_leave_removes_the_covered_early_minutes(): void
    {
        // Jam tutup 18:00, pulang 16:00 = 120 menit cepat; izin pulang mulai 15:00 menutup seluruhnya.
        $row = $this->clockRow('2026-11-11', '09:00', '16:00', 0, 120);
        $request = $this->early(['date' => '2026-11-11', 'time' => '15:00']);

        $this->service->approve($request, $this->manager);

        $this->assertSame(0, $row->fresh()->early_leave_minutes);
    }

    public function test_leaving_the_office_only_records_and_changes_no_count(): void
    {
        $row = $this->clockRow('2026-11-11', '11:30', '16:00', 150, 120);
        $request = $this->out(['date' => '2026-11-11']);

        $this->service->approve($request, $this->manager);

        $fresh = $row->fresh();
        $this->assertSame([150, 120], [$fresh->late_minutes, $fresh->early_leave_minutes]);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_a_pending_or_rejected_request_changes_nothing(): void
    {
        $row = $this->clockRow('2026-11-11', '11:30', null, 150);
        $request = $this->late(['date' => '2026-11-11']);

        $this->assertSame(150, $row->fresh()->late_minutes);

        $this->service->reject($request, $this->manager, 'Tidak sesuai');
        $row->fresh()->recalculateTiming();

        $this->assertSame(150, $row->fresh()->late_minutes);
    }

    public function test_the_approval_does_not_touch_the_annual_leave_quota(): void
    {
        $before = LeaveRequest::count();
        $this->service->approve($this->late(), $this->manager);

        $this->assertSame($before, LeaveRequest::count(), 'Izin sebagian hari tidak membuat atau memotong izin/cuti sehari penuh.');
    }

    // ------------------------------------------------------------- keputusan

    public function test_the_requester_and_other_store_managers_cannot_decide(): void
    {
        $request = $this->late();

        $this->assertRejected(fn () => $this->service->approve($request, $this->andi), 'Tidak boleh memutuskan');
        $this->assertRejected(fn () => $this->service->approve($request, $this->otherManager), 'Hanya akses penuh');

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_both_the_store_manager_and_full_access_can_decide(): void
    {
        $this->service->approve($this->late(), $this->manager);
        $this->assertNotEmpty($this->andi->notifications()->get()->where('data.title', 'Pengajuan Izin Sebagian Hari Disetujui'));

        $request = $this->early();
        $this->service->reject($request, $this->admin, 'Terlalu sering');
        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertNotEmpty($this->andi->notifications()->get()->where('data.title', 'Pengajuan Izin Sebagian Hari Ditolak'));
    }

    public function test_rejection_needs_a_reason_and_a_decided_request_cannot_be_decided_again(): void
    {
        $request = $this->late();

        $this->assertRejected(fn () => $this->service->reject($request, $this->manager, ' '), 'Alasan penolakan');

        $this->service->approve($request, $this->manager);
        $this->assertRejected(fn () => $this->service->approve($request, $this->manager), 'sudah diputuskan');
    }

    public function test_cancelling_is_for_pending_or_future_approved_requests_only(): void
    {
        $pending = $this->late();
        $this->service->cancel($pending, $this->andi->id);
        $this->assertSame('cancelled', $pending->fresh()->status);

        $future = $this->late(['date' => '2026-11-13']);
        $this->service->approve($future, $this->manager);
        $this->service->cancel($future, $this->andi->id);
        $this->assertSame('cancelled', $future->fresh()->status, 'Disetujui untuk tanggal yang belum tiba boleh ditarik.');

        $today = $this->early();
        $this->service->approve($today, $this->manager);
        $this->assertRejected(fn () => $this->service->cancel($today, $this->andi->id), 'tidak bisa dibatalkan');
        $this->assertRejected(fn () => $this->service->cancel($this->late(['date' => '2026-11-11']), $this->manager->id), 'bukan milik');
    }

    // ------------------------------------------------------------- Pusat Persetujuan

    public function test_the_approval_center_lists_and_decides_it_inline(): void
    {
        $request = $this->late();
        $center = app(ApprovalCenterService::class);

        $items = $center->items($this->manager, 'partial_leave');
        $this->assertCount(1, $items);
        $this->assertSame('Izin Sebagian Hari', $items->first()->typeLabel);
        $this->assertCount(0, $center->items($this->otherManager, 'partial_leave'), 'Store manager toko lain tidak melihatnya.');

        $message = $center->source('partial_leave')->approve($request->id, $this->manager);

        $this->assertSame('Izin sebagian hari disetujui.', $message);
        $this->assertSame('approved', $request->fresh()->status);
    }

    // ------------------------------------------------------------- API aplikasi

    public function test_the_app_api_submits_lists_and_cancels_own_requests(): void
    {
        $created = $this->actingAs($this->andi, 'api')->postJson('/api/staff/partial-leave-requests', [
            'date' => '2026-11-12', 'category' => 'late_arrival', 'time' => '10:30', 'reason' => 'Mengantar anak',
        ])->assertStatus(201)->assertJsonPath('partial_leave_request.category_label', 'Terlambat Masuk Kerja');

        $id = $created->json('partial_leave_request.id');

        $this->actingAs($this->andi, 'api')->getJson('/api/staff/partial-leave-requests')
            ->assertSuccessful()
            ->assertJsonPath('partial_leave_requests.0.id', $id)
            ->assertJsonPath('partial_leave_requests.0.window_label', 'Masuk paling lambat 10:30')
            ->assertJsonPath('categories.0.value', 'late_arrival');

        // Milik orang lain tidak terlihat dan tidak bisa dibatalkan.
        $this->assertCount(0, $this->actingAs($this->manager, 'api')->getJson('/api/staff/partial-leave-requests')->json('partial_leave_requests'));
        $this->actingAs($this->manager, 'api')->postJson("/api/staff/partial-leave-requests/{$id}/cancel")->assertNotFound();

        $this->actingAs($this->andi, 'api')->postJson("/api/staff/partial-leave-requests/{$id}/cancel")->assertSuccessful();
        $this->assertSame('cancelled', PartialLeaveRequest::withoutGlobalScopes()->findOrFail($id)->status);
    }

    public function test_the_app_api_validates_the_time_per_category(): void
    {
        $url = '/api/staff/partial-leave-requests';
        $base = ['date' => '2026-11-12', 'reason' => 'Urusan pribadi'];

        $this->actingAs($this->andi, 'api')->postJson($url, $base + ['category' => 'late_arrival'])->assertStatus(422)->assertJsonValidationErrors('time');
        $this->actingAs($this->andi, 'api')->postJson($url, $base + ['category' => 'leave_office', 'start_time' => '12:00'])->assertStatus(422)->assertJsonValidationErrors('end_time');
        $this->actingAs($this->andi, 'api')->postJson($url, $base + ['category' => 'lainnya', 'time' => '10:00'])->assertStatus(422)->assertJsonValidationErrors('category');
    }
}
