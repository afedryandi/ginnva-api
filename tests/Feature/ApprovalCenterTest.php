<?php

namespace Tests\Feature;

use App\Filament\Pages\ApprovalCenter;
use App\Mail\ApprovalRequestedMail;
use App\Models\Attendance;
use App\Models\FieldDutyRequest;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PurchaseRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\ApprovalCenterService;
use App\Services\ApprovalMailer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pusat Persetujuan: semua pengajuan menunggu dalam satu halaman, diputuskan langsung lewat layanan keputusan yang sama dengan menunya.
 * "Sekarang" dibekukan Kamis 12 November 2026 pukul 10:00.
 */
class ApprovalCenterTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private User $andi;
    private User $manager;
    private User $otherManager;
    private User $admin;
    private ApprovalCenterService $center;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Cache::flush();
        foreach (['kasir', 'store_manager', 'super_admin', 'direksi'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-11-12 10:00:00');

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->andi = $this->user('kasir', $this->store, 'Andi', 'andi@test.local');
        $this->manager = $this->user('store_manager', $this->store, 'Manajer A', 'manajer@test.local');
        $this->otherManager = $this->user('store_manager', $this->otherStore, 'Manajer B', 'manajerb@test.local');
        $this->admin = $this->user('super_admin', null, 'Direksi', 'direksi@test.local');
        $this->center = app(ApprovalCenterService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, ?Store $store, string $name, string $email): User
    {
        return tap(User::create(['name' => $name, 'email' => $email, 'password' => 'x', 'store_id' => $store?->id, 'join_date' => '2025-01-01', 'is_active' => true]), fn (User $u) => $u->assignRole($role));
    }

    private function overtime(string $createdAt = '2026-11-12 08:00:00'): OvertimeRequest
    {
        return tap(OvertimeRequest::create([
            'user_id' => $this->andi->id, 'store_id' => $this->store->id, 'date' => '2026-11-13',
            'starts_at' => '2026-11-13 18:00', 'ends_at' => '2026-11-13 20:00', 'minutes' => 120, 'description' => 'Pasang PPF mobil', 'status' => 'pending',
        ]), fn ($r) => $r->forceFill(['created_at' => $createdAt])->save());
    }

    private function fieldDuty(): FieldDutyRequest
    {
        return FieldDutyRequest::create(['user_id' => $this->andi->id, 'store_id' => $this->store->id, 'date' => '2026-11-13', 'start_time' => '10:00', 'end_time' => '13:00', 'description' => 'Kanvasing area Kelapa Gading', 'status' => 'pending']);
    }

    private function leave(): LeaveRequest
    {
        return LeaveRequest::create(['user_id' => $this->andi->id, 'store_id' => $this->store->id, 'type' => 'izin', 'start_date' => '2026-11-13', 'end_date' => '2026-11-13', 'reason' => 'Urusan keluarga', 'status' => 'pending']);
    }

    private function purchase(): PurchaseRequest
    {
        return PurchaseRequest::create(['store_id' => $this->store->id, 'item_type' => 'raw_material', 'item_name' => 'Cairan Slip', 'unit' => 'liter', 'quantity' => 10, 'reason' => 'Stok menipis', 'status' => 'pending', 'requested_by' => $this->andi->id]);
    }

    public function test_each_person_only_sees_what_they_may_decide_oldest_first(): void
    {
        $this->overtime('2026-11-12 08:00:00');
        $this->fieldDuty();
        $this->leave();
        $this->purchase();

        $adminItems = $this->center->items($this->admin);
        $this->assertEqualsCanonicalizing(['overtime', 'field_duty', 'leave', 'purchase_request'], $adminItems->pluck('source')->all());
        $this->assertSame('overtime', $adminItems->first()->source, 'Yang paling lama menunggu di atas.');

        // Store manager toko itu: lembur, dinas luar, izin; TIDAK permohonan pembelian (hanya akses penuh).
        $this->assertEqualsCanonicalizing(['overtime', 'field_duty', 'leave'], $this->center->items($this->manager)->pluck('source')->all());

        $this->assertCount(0, $this->center->items($this->otherManager), 'Store manager toko lain tidak melihat pengajuan toko ini.');
        $this->assertFalse($this->center->canUse($this->andi), 'Karyawan biasa tidak punya Pusat Persetujuan.');
        $this->assertTrue($this->center->canUse($this->admin));
        $this->assertTrue($this->center->canUse($this->manager));
    }

    public function test_overtime_is_decided_inline_and_cannot_be_decided_twice(): void
    {
        $request = $this->overtime();
        $source = $this->center->source('overtime');

        $this->assertSame('Lembur disetujui.', $source->approve($request->id, $this->admin));
        $this->assertSame('approved', $request->fresh()->status);

        $this->expectException(\RuntimeException::class);
        $source->approve($request->id, $this->admin);
    }

    public function test_rejection_needs_a_reason_and_is_recorded(): void
    {
        $request = $this->overtime();

        $this->center->source('overtime')->reject($request->id, $this->manager, 'Tidak ada pekerjaan yang jelas');

        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame('Tidak ada pekerjaan yang jelas', $request->fresh()->review_notes);
    }

    public function test_purchase_request_can_be_approved_with_a_smaller_quantity_and_only_by_full_access(): void
    {
        $request = $this->purchase();
        $source = $this->center->source('purchase_request');

        try {
            $source->approve($request->id, $this->manager, ['quantity_approved' => 5]);
            $this->fail('Store manager tidak boleh memutuskan Permohonan Pembelian.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('akses penuh', $e->getMessage());
        }

        $source->approve($request->id, $this->admin, ['quantity_approved' => 5]);

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertEquals(5, $request->fresh()->quantity_approved);
    }

    public function test_leave_approval_turns_an_alpha_day_into_leave_like_the_menu_does(): void
    {
        $request = $this->leave();
        Attendance::create(['user_id' => $this->andi->id, 'store_id' => $this->store->id, 'date' => '2026-11-13', 'entry_type' => 'alpha']);

        $this->center->source('leave')->approve($request->id, $this->manager);

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame('leave', Attendance::where('user_id', $this->andi->id)->whereDate('date', '2026-11-13')->value('entry_type'));
    }

    public function test_a_partial_field_duty_window_can_be_adjusted_when_approving(): void
    {
        $request = $this->fieldDuty();

        $this->center->source('field_duty')->approve($request->id, $this->manager, ['start_time' => '09:00', 'end_time' => '14:00']);

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame('09:00–14:00', $request->fresh()->windowLabel());
    }

    public function test_the_badge_counts_what_is_waiting_and_drops_after_a_decision(): void
    {
        $first = $this->overtime();
        $this->leave();

        $this->assertSame(2, $this->center->badgeCount($this->admin));

        $this->center->source('overtime')->approve($first->id, $this->admin);
        $this->center->forgetBadge($this->admin);

        $this->assertSame(1, $this->center->badgeCount($this->admin));
    }

    public function test_link_only_sources_have_no_inline_decision(): void
    {
        $warranty = $this->center->source('warranty');

        $this->assertFalse($warranty->inline());

        $this->expectException(\RuntimeException::class);
        $warranty->approve(1, $this->admin);
    }

    public function test_the_page_lists_the_requests_and_decides_from_its_buttons(): void
    {
        $request = $this->overtime();
        $this->actingAs($this->admin);

        Livewire::test(ApprovalCenter::class)
            ->assertSuccessful()
            ->assertSee('Lembur')
            ->assertSee('Andi')
            ->callAction('approve', arguments: ['source' => 'overtime', 'id' => $request->id])
            ->assertHasNoActionErrors();

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_the_shortcut_is_a_topbar_icon_with_a_count_not_a_menu_tab(): void
    {
        $this->assertFalse(ApprovalCenter::shouldRegisterNavigation(), 'Bilah menu atas sudah penuh; pintasannya ikon di samping lonceng.');

        $this->overtime();
        $this->leave();

        $this->actingAs($this->admin);
        $html = view('filament.partials.approval-topbar-link')->render();
        $this->assertStringContainsString('pusat-persetujuan', $html);
        $this->assertStringContainsString('2', $html);

        $this->actingAs($this->andi);
        $this->assertSame('', trim(view('filament.partials.approval-topbar-link')->render()), 'Karyawan biasa tidak melihat pintasannya.');
    }

    public function test_the_page_is_closed_to_ordinary_staff(): void
    {
        $this->actingAs($this->andi);

        $this->assertFalse(ApprovalCenter::canAccess());
    }

    public function test_the_email_goes_only_to_full_access_and_can_be_switched_off(): void
    {
        Mail::fake();
        config(['approvals.email' => true]);

        app(ApprovalMailer::class)->requested('Lembur', 'Andi', '13 Nov 2026 pukul 18:00–20:00', 'Toko A', [$this->admin->id, $this->manager->id]);

        Mail::assertSent(ApprovalRequestedMail::class, 1);
        Mail::assertSent(ApprovalRequestedMail::class, fn (ApprovalRequestedMail $mail) => $mail->hasTo('direksi@test.local') && str_contains($mail->url, 'pusat-persetujuan'));

        Mail::fake();
        config(['approvals.email' => false]);

        app(ApprovalMailer::class)->requested('Lembur', 'Andi', 'ringkas', 'Toko A', [$this->admin->id]);

        Mail::assertNothingSent();
    }
}
