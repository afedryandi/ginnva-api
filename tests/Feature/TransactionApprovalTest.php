<?php

namespace Tests\Feature;

use App\Filament\Resources\TransactionApprovalRequestResource;
use App\Filament\Resources\TransactionApprovalRequestResource\Pages\ListTransactionApprovalRequests;
use App\Models\AccountingPeriod;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\TransactionApprovalRequest;
use App\Models\User;
use App\Services\BookingPostingService;
use App\Services\TransactionApprovalService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Persetujuan Transaksi (Proses Referral / Refund / Catat DP dari staff non-full-access): pengajuan satu per
 * (booking, jenis) dengan payload whitelist, eksekusi saat disetujui (nominal & jurnal), pemisahan tugas
 * (hanya full-access yang bukan pengaju), keputusan tunggal (sekali putus), kegagalan eksekusi tidak meninggalkan
 * efek setengah jadi, batal / ajukan ulang, notifikasi, pengingat harian, dan layar admin (visibilitas per peran,
 * aksi tabel, tab, badge).
 */
class TransactionApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private TransactionApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'store_manager', 'spv_finance', 'direksi'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->service = app(TransactionApprovalService::class);
    }

    private function user(string $role, ?Store $store = null): User
    {
        return tap(User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id]), fn (User $u) => $u->assignRole($role));
    }

    private function staff(?Store $store = null): User
    {
        return $this->user('kasir', $store);
    }

    private function admin(): User
    {
        return $this->user('super_admin');
    }

    private function booking(array $overrides = [], ?Store $store = null): Booking
    {
        $customer = Customer::create(['name' => 'Budi', 'phone_number' => '0812' . random_int(10000000, 99999999)]);

        return Booking::create(array_merge([
            'booking_number' => 'BKG-TEST-' . uniqid(),
            'customer_id' => $customer->id,
            'store_id' => ($store ?? $this->store)->id,
            'service_type' => 'PPF',
            'product_ppf' => true,
            'preferred_date' => now()->toDateString(),
            'status' => 'pending',
        ], $overrides));
    }

    /** Booking selesai yang sudah punya jurnal pendapatan (syarat refund). */
    private function postedBooking(float $amount = 1000000): Booking
    {
        $booking = $this->booking(['status' => 'completed', 'transaction_amount' => $amount, 'amount_received' => $amount]);
        app(BookingPostingService::class)->sync($booking);

        return $booking->fresh();
    }

    private function referral(Booking $booking, User $by, float $amount = 1000000, array $extra = []): TransactionApprovalRequest
    {
        return $this->service->submitBookingReferral($booking, $amount, $amount, $extra, $by->id);
    }

    private function assertRefused(callable $action, string $fragment): void
    {
        try {
            $action();
            $this->fail('Seharusnya ditolak: ' . $fragment);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    // ------------------------------------------------------------- pengajuan

    public function test_a_submission_is_pending_numbered_scoped_to_the_booking_store_and_notifies_other_admins(): void
    {
        $staff = $this->staff();
        $admin = $this->admin();
        $booking = $this->booking();

        $request = $this->service->submitDownPayment($booking, 500000, 'DP awal', $staff->id);

        $this->assertSame('pending', $request->status);
        $this->assertMatchesRegularExpression('/^APR-\d{6}-[A-Z0-9]{4}$/', $request->request_number);
        $this->assertSame($this->store->id, $request->store_id);
        $this->assertSame(['amount' => 500000, 'notes' => 'DP awal'], $request->payload);
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $staff->notifications()->count());
    }

    public function test_payload_is_whitelisted_per_type(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();

        $referral = $this->referral($booking, $staff, 750000, ['referral_code' => 'ABC123', 'payment_method' => 'cash', 'hack' => 'drop table', 'status' => 'approved']);
        $refund = $this->service->submitRefund($this->postedBooking(), 1000, 'Alasan', $staff->id);

        $this->assertSame(['transaction_amount', 'amount_received', 'referral_code', 'payment_method'], array_keys($referral->payload));
        $this->assertSame('pending', $referral->status);
        $this->assertSame(['amount', 'reason'], array_keys($refund->payload));
    }

    public function test_invalid_amounts_are_refused(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();

        $this->assertRefused(fn () => $this->service->submitRefund($booking, 0, null, $staff->id), 'Nominal pengajuan tidak valid');
        $this->assertRefused(fn () => $this->service->submitDownPayment($booking, -5, null, $staff->id), 'Nominal pengajuan tidak valid');
        $this->assertRefused(fn () => $this->service->submitBookingReferral($booking, -1, null, [], $staff->id), 'Nominal pengajuan tidak valid');
        $this->assertSame(0, TransactionApprovalRequest::count());
    }

    public function test_only_one_pending_request_per_booking_and_type(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();
        $first = $this->service->submitDownPayment($booking, 100000, null, $staff->id);

        $this->assertRefused(fn () => $this->service->submitDownPayment($booking, 200000, null, $staff->id), 'masih menunggu persetujuan');

        $this->assertSame('pending', $this->service->submitBookingReferral($booking, 500000, 500000, [], $staff->id)->status, 'Jenis lain boleh.');
        $this->assertSame('pending', $this->service->submitDownPayment($this->booking(), 100000, null, $staff->id)->status, 'Booking lain boleh.');

        $this->service->reject($first, $this->admin()->id, 'Salah nominal');
        $this->assertSame('pending', $this->service->submitDownPayment($booking, 200000, null, $staff->id)->status, 'Setelah diputuskan boleh mengajukan lagi.');
    }

    // ------------------------------------------------------------- persetujuan

    public function test_approving_a_referral_updates_the_booking_posts_the_journal_and_notifies_the_requester(): void
    {
        $staff = $this->staff();
        $admin = $this->admin();
        $booking = $this->booking(['status' => 'completed']);
        $request = $this->referral($booking, $staff, 1000000);

        $this->service->approve($request, $admin->id);

        $booking->refresh();
        $this->assertEquals(1000000, $booking->transaction_amount);
        $this->assertEquals(1000000, $booking->amount_received);
        $this->assertNotNull($booking->journal_entry_id, 'Nominal diposting ke Jurnal Umum.');
        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($admin->id, $request->decided_by);
        $this->assertNotNull($request->decided_at);
        $this->assertSame(1, $staff->notifications()->count());
        $this->assertStringContainsString('disetujui', $staff->notifications()->first()->data['title']);
    }

    public function test_approving_a_refund_creates_the_refund_attributed_to_the_requester(): void
    {
        $staff = $this->staff();
        $booking = $this->postedBooking(1000000);
        $request = $this->service->submitRefund($booking, 300000, 'Batal sebagian', $staff->id);

        $this->service->approve($request, $this->admin()->id);

        $refund = $booking->refunds()->firstOrFail();
        $this->assertEquals(300000, $refund->amount);
        $this->assertSame($staff->id, $refund->created_by);
        $this->assertNotNull($refund->journal_entry_id);
    }

    public function test_approving_a_down_payment_records_deferred_revenue(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();
        $request = $this->service->submitDownPayment($booking, 400000, 'DP awal', $staff->id);

        $this->service->approve($request, $this->admin()->id);

        $dp = $booking->downPayments()->firstOrFail();
        $this->assertEquals(400000, $dp->amount);
        $this->assertSame($staff->id, $dp->created_by);
        $this->assertSame('2140', $dp->journalEntry->lines()->where('credit', '>', 0)->first()->account->code);
    }

    public function test_a_request_can_be_decided_only_once(): void
    {
        $staff = $this->staff();
        $admin = $this->admin();
        $booking = $this->booking();
        $request = $this->service->submitDownPayment($booking, 100000, null, $staff->id);

        $this->service->approve($request, $admin->id);

        $this->assertRefused(fn () => $this->service->approve($request, $admin->id), 'sudah diputuskan');
        $this->assertRefused(fn () => $this->service->reject($request, $admin->id, 'Telat'), 'sudah diputuskan');
        $this->assertSame(1, $booking->downPayments()->count(), 'DP tidak tercatat ganda.');

        $rejected = $this->service->submitRefund($this->postedBooking(), 1000, null, $staff->id);
        $this->service->reject($rejected, $admin->id, 'Tidak sesuai');
        $this->assertRefused(fn () => $this->service->approve($rejected, $admin->id), 'sudah diputuskan');
    }

    // ------------------------------------------------------------- pemisahan tugas

    public function test_only_other_full_access_users_may_decide(): void
    {
        $booking = $this->booking();
        $admin = $this->admin();
        $adminRequest = $this->service->submitDownPayment($booking, 100000, null, $admin->id);

        $this->assertRefused(fn () => $this->service->approve($adminRequest, $admin->id), 'tidak boleh memutuskan pengajuan yang Anda ajukan sendiri');
        $this->assertRefused(fn () => $this->service->reject($adminRequest, $admin->id, 'x'), 'tidak boleh memutuskan pengajuan yang Anda ajukan sendiri');

        foreach ([$this->user('store_manager'), $this->user('spv_finance'), $this->staff()] as $nonAdmin) {
            $this->assertRefused(fn () => $this->service->approve($adminRequest, $nonAdmin->id), 'tidak berwenang');
            $this->assertRefused(fn () => $this->service->reject($adminRequest, $nonAdmin->id, 'x'), 'tidak berwenang');
        }
        $this->assertRefused(fn () => $this->service->approve($adminRequest, 999999), 'tidak berwenang');

        $other = $this->user('direksi');
        $this->service->approve($adminRequest, $other->id);
        $this->assertSame('approved', $adminRequest->fresh()->status);
    }

    public function test_rejecting_requires_a_reason_records_it_and_notifies_the_requester(): void
    {
        $staff = $this->staff();
        $admin = $this->admin();
        $request = $this->service->submitDownPayment($this->booking(), 100000, null, $staff->id);

        $this->assertRefused(fn () => $this->service->reject($request, $admin->id, '  '), 'Alasan penolakan wajib');
        $this->assertSame('pending', $request->fresh()->status);

        $this->service->reject($request, $admin->id, 'Nominal tidak cocok');

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertSame('Nominal tidak cocok', $request->decision_note);
        $this->assertSame($admin->id, $request->decided_by);
        $this->assertStringContainsString('ditolak', $staff->notifications()->first()->data['title']);
    }

    // ------------------------------------------------------------- kegagalan eksekusi

    public function test_a_refund_larger_than_the_remaining_amount_cannot_be_approved_and_leaves_no_trace(): void
    {
        $staff = $this->staff();
        $booking = $this->postedBooking(1000000);
        $request = $this->service->submitRefund($booking, 1500000, null, $staff->id);

        $this->assertRefused(fn () => $this->service->approve($request, $this->admin()->id), 'melebihi sisa');

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, $booking->refunds()->count());
    }

    public function test_a_referral_cannot_overwrite_the_amount_of_a_booking_that_already_has_a_refund(): void
    {
        $staff = $this->staff();
        $booking = $this->postedBooking(1000000);
        app(\App\Services\RefundService::class)->process($booking, 100000, null, null);
        $request = $this->referral($booking->fresh(), $staff, 800000);

        $this->assertRefused(fn () => $this->service->approve($request, $this->admin()->id), 'sudah punya refund');

        $this->assertEquals(1000000, $booking->fresh()->transaction_amount);
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_a_closed_period_stops_the_approval_and_the_request_stays_pending(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();
        $request = $this->service->submitDownPayment($booking, 100000, null, $staff->id);
        AccountingPeriod::create(['period_month' => now()->startOfMonth()->toDateString(), 'closed_by' => $this->admin()->id, 'closed_at' => now()]);

        $this->assertRefused(fn () => $this->service->approve($request, $this->admin()->id), 'sudah ditutup');

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, $booking->downPayments()->count());
    }

    public function test_a_down_payment_for_a_booking_completed_meanwhile_is_stuck_not_half_recorded(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();
        $request = $this->service->submitDownPayment($booking, 100000, null, $staff->id);
        $booking->update(['status' => 'completed']);

        $this->assertRefused(fn () => $this->service->approve($request, $this->admin()->id), 'tidak bisa mencatat DP baru');

        $this->assertSame('pending', $request->fresh()->status);
    }

    // ------------------------------------------------------------- batal & ajukan ulang

    public function test_only_the_requester_can_cancel_and_only_while_pending(): void
    {
        $staff = $this->staff();
        $request = $this->service->submitDownPayment($this->booking(), 100000, null, $staff->id);

        $this->assertRefused(fn () => $this->service->cancel($request, $this->staff()), 'Cuma pengaju');
        $this->assertRefused(fn () => $this->service->cancel($request, $this->admin()), 'Cuma pengaju');

        $this->service->cancel($request, $staff);
        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertRefused(fn () => $this->service->cancel($request, $staff), 'sudah diputuskan');
        $this->assertRefused(fn () => $this->service->approve($request, $this->admin()->id), 'sudah diputuskan');

        $approved = $this->service->submitDownPayment($this->booking(), 100000, null, $staff->id);
        $this->service->approve($approved, $this->admin()->id);
        $this->assertRefused(fn () => $this->service->cancel($approved, $staff), 'sudah diputuskan');
    }

    public function test_resubmitting_a_rejected_or_cancelled_request_creates_a_new_pending_one_once(): void
    {
        $staff = $this->staff();
        $admin = $this->admin();
        $booking = $this->booking();
        $original = $this->referral($booking, $staff, 640000, ['referral_code' => 'REF77', 'payment_method' => 'cash']);
        $this->service->reject($original, $admin->id, 'Cek ulang');

        $new = $this->service->resubmit($original->fresh(), $staff);

        $this->assertSame('pending', $new->status);
        $this->assertNotSame($original->id, $new->id);
        $this->assertNotSame($original->request_number, $new->request_number);
        $this->assertEquals(640000, $new->payload['transaction_amount']);
        $this->assertSame('REF77', $new->payload['referral_code']);
        $this->assertSame('cash', $new->payload['payment_method']);
        $this->assertNotNull($original->fresh()->resubmitted_at);

        $this->assertRefused(fn () => $this->service->resubmit($original->fresh(), $staff), 'sudah pernah diajukan ulang');

        $cancelled = $this->service->submitRefund($this->postedBooking(), 5000, 'x', $staff->id);
        $this->service->cancel($cancelled, $staff);
        $this->assertSame('pending', $this->service->resubmit($cancelled->fresh(), $staff)->status);
    }

    public function test_resubmit_guards(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();
        $pending = $this->service->submitDownPayment($booking, 100000, null, $staff->id);

        $this->assertRefused(fn () => $this->service->resubmit($pending, $staff), 'ditolak/dibatalkan');

        $this->service->reject($pending, $this->admin()->id, 'Salah');
        $this->assertRefused(fn () => $this->service->resubmit($pending->fresh(), $this->staff()), 'pengaju asli');

        $this->service->submitDownPayment($booking, 300000, null, $staff->id);
        $this->assertRefused(fn () => $this->service->resubmit($pending->fresh(), $staff), 'masih menunggu persetujuan');
        $this->assertNull($pending->fresh()->resubmitted_at, 'Gagal ajukan ulang tidak menandai pengajuan lama.');
    }

    public function test_the_stuck_notice_reaches_the_requester(): void
    {
        $staff = $this->staff();
        $request = $this->service->submitDownPayment($this->booking(), 100000, null, $staff->id);

        $this->service->notifyRequesterStuck($request, 'Periode sudah ditutup');

        $this->assertSame('Pengajuan Anda tertahan', $staff->notifications()->first()->data['title']);
    }

    // ------------------------------------------------------------- pengingat harian

    public function test_the_daily_reminder_covers_only_stale_pending_requests_and_skips_the_requester(): void
    {
        $staff = $this->staff();
        $adminA = $this->admin();
        $adminB = $this->admin();
        $stale = $this->service->submitDownPayment($this->booking(), 100000, null, $staff->id);
        $adminOwn = $this->service->submitDownPayment($this->booking(), 100000, null, $adminA->id);
        $fresh = $this->service->submitDownPayment($this->booking(), 100000, null, $staff->id);
        $decided = $this->service->submitDownPayment($this->booking(), 100000, null, $staff->id);
        $this->service->reject($decided, $adminA->id, 'x');
        DB::table('transaction_approval_requests')->whereIn('id', [$stale->id, $adminOwn->id, $decided->id])->update(['created_at' => now()->subDays(3)]);
        DB::table('notifications')->delete();

        $this->artisan('transactions:remind-pending-approvals')->assertSuccessful();

        $this->assertSame(1, $adminA->notifications()->count());
        $this->assertStringContainsString('2 pengajuan', $adminB->notifications()->first()->data['title'] ?? '', 'adminB: stale milik staff + milik adminA.');
        $this->assertStringContainsString('1 pengajuan', $adminA->notifications()->first()->data['title'], 'adminA tidak diingatkan soal pengajuannya sendiri.');
        $this->assertSame(0, $staff->notifications()->count());
    }

    public function test_the_reminder_is_quiet_when_nothing_is_stale(): void
    {
        $this->admin();
        $this->service->submitDownPayment($this->booking(), 100000, null, $this->staff()->id);
        DB::table('notifications')->delete();

        $this->artisan('transactions:remind-pending-approvals')->expectsOutput('Tidak ada pengajuan yang menggantung.')->assertSuccessful();

        $this->assertSame(0, DB::table('notifications')->count());
    }

    // ------------------------------------------------------------- layar admin

    private function listFor(User $user, string $tab = 'semua')
    {
        $this->actingAs($user, 'web');

        return Livewire::test(ListTransactionApprovalRequests::class)->set('activeTab', $tab);
    }

    public function test_visibility_per_role(): void
    {
        $staffA = $this->staff();
        $staffA2 = $this->staff();
        $staffB = $this->staff($this->otherStore);
        $mine = $this->service->submitDownPayment($this->booking(), 1000, null, $staffA->id);
        $sameStore = $this->service->submitDownPayment($this->booking(), 2000, null, $staffA2->id);
        $otherStore = $this->service->submitDownPayment($this->booking([], $this->otherStore), 3000, null, $staffB->id);

        $this->listFor($staffA)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$sameStore, $otherStore]);
        $this->listFor($this->user('store_manager'))->assertCanSeeTableRecords([$mine, $sameStore])->assertCanNotSeeTableRecords([$otherStore]);
        $this->listFor($this->admin())->assertCanSeeTableRecords([$mine, $sameStore, $otherStore]);
    }

    public function test_access_and_navigation_badge(): void
    {
        $this->service->submitDownPayment($this->booking(), 1000, null, $this->staff()->id);
        $this->service->submitDownPayment($this->booking(), 1000, null, $this->staff()->id);

        $this->actingAs($this->admin(), 'web');
        $this->assertTrue(TransactionApprovalRequestResource::canViewAny());
        $this->assertSame('2', TransactionApprovalRequestResource::getNavigationBadge());
        $this->assertFalse(TransactionApprovalRequestResource::canCreate());
        $this->assertFalse(TransactionApprovalRequestResource::canDelete(new TransactionApprovalRequest()));

        $this->actingAs($this->staff(), 'web');
        $this->assertTrue(TransactionApprovalRequestResource::canViewAny());
        $this->assertNull(TransactionApprovalRequestResource::getNavigationBadge(), 'Badge hanya untuk yang berwenang memutuskan.');
    }

    public function test_tabs_filters_and_columns(): void
    {
        $staff = $this->staff();
        $admin = $this->admin();
        $pending = $this->service->submitDownPayment($this->booking(), 1000, null, $staff->id);
        $approved = $this->service->submitDownPayment($this->booking(), 2000, null, $staff->id);
        $this->service->approve($approved, $admin->id);
        $rejected = $this->service->submitRefund($this->postedBooking(), 3000, null, $staff->id);
        $this->service->reject($rejected, $admin->id, 'x');
        $cancelled = $this->service->submitDownPayment($this->booking(), 4000, null, $staff->id);
        $this->service->cancel($cancelled, $staff);

        $this->listFor($admin, 'menunggu')->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$approved, $rejected, $cancelled]);
        $this->listFor($admin, 'disetujui')->assertCanSeeTableRecords([$approved])->assertCanNotSeeTableRecords([$pending]);
        $this->listFor($admin, 'ditolak')->assertCanSeeTableRecords([$rejected, $cancelled])->assertCanNotSeeTableRecords([$pending, $approved]);
        $this->listFor($admin)->filterTable('type', 'refund')->assertCanSeeTableRecords([$rejected])->assertCanNotSeeTableRecords([$pending, $approved])
            ->assertTableColumnStateSet('nominal', 'Rp3.000', $rejected);
    }

    public function test_approve_and_reject_buttons_follow_the_separation_of_duties(): void
    {
        $staff = $this->staff();
        $admin = $this->admin();
        $otherAdmin = $this->admin();
        $byStaff = $this->service->submitDownPayment($this->booking(), 1000, null, $staff->id);
        $byAdmin = $this->service->submitDownPayment($this->booking(), 2000, null, $admin->id);

        $this->listFor($admin, 'menunggu')
            ->assertTableActionVisible('approve', $byStaff)->assertTableActionVisible('reject', $byStaff)
            ->assertTableActionHidden('approve', $byAdmin)->assertTableActionHidden('reject', $byAdmin)
            ->assertTableActionVisible('cancelRequest', $byAdmin);
        $this->listFor($otherAdmin, 'menunggu')->assertTableActionVisible('approve', $byAdmin);
        $this->listFor($this->user('store_manager'), 'menunggu')->assertTableActionHidden('approve', $byStaff)->assertTableActionHidden('reject', $byStaff);
        $this->listFor($staff, 'menunggu')->assertTableActionHidden('approve', $byStaff)->assertTableActionVisible('cancelRequest', $byStaff);
    }

    public function test_approving_from_the_table_executes_the_request(): void
    {
        $staff = $this->staff();
        $booking = $this->booking();
        $request = $this->service->submitDownPayment($booking, 250000, null, $staff->id);

        $this->listFor($this->admin(), 'menunggu')->callTableAction('approve', $request)->assertHasNoTableActionErrors();

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(1, $booking->downPayments()->count());
    }

    public function test_a_failing_approval_from_the_table_keeps_it_pending_and_tells_the_requester(): void
    {
        $staff = $this->staff();
        $booking = $this->postedBooking(1000000);
        $request = $this->service->submitRefund($booking, 5000000, null, $staff->id);

        $this->listFor($this->admin(), 'menunggu')->callTableAction('approve', $request);

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('Pengajuan Anda tertahan', $staff->notifications()->first()->data['title']);
    }

    public function test_rejecting_from_the_table_needs_a_note(): void
    {
        $request = $this->service->submitDownPayment($this->booking(), 1000, null, $this->staff()->id);

        $this->listFor($this->admin(), 'menunggu')
            ->callTableAction('reject', $request, data: ['decision_note' => ''])
            ->assertHasTableActionErrors(['decision_note' => 'required']);
        $this->assertSame('pending', $request->fresh()->status);

        $this->listFor($this->admin(), 'menunggu')->callTableAction('reject', $request, data: ['decision_note' => 'Tidak valid']);
        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_cancel_and_resubmit_from_the_table(): void
    {
        $staff = $this->staff();
        $request = $this->service->submitDownPayment($this->booking(), 1000, null, $staff->id);

        $this->listFor($staff, 'menunggu')->callTableAction('cancelRequest', $request);
        $this->assertSame('cancelled', $request->fresh()->status);

        $this->listFor($staff)->assertTableActionVisible('resubmit', $request->fresh())->callTableAction('resubmit', $request->fresh());
        $this->assertSame(2, TransactionApprovalRequest::count());
        $this->listFor($staff)->assertTableActionHidden('resubmit', $request->fresh());
        $this->listFor($this->admin())->assertTableActionHidden('resubmit', $request->fresh());
    }

    public function test_the_detail_modal_shows_the_summary_and_the_decision(): void
    {
        $staff = $this->staff();
        $admin = $this->admin();
        $request = $this->service->submitRefund($this->postedBooking(), 5000, 'Customer batal', $staff->id);
        $this->service->reject($request, $admin->id, 'Dokumen kurang');

        $this->listFor($admin)->mountTableAction('view', $request->fresh())->assertSee('Customer batal')->assertSee('Dokumen kurang')->assertSee($request->request_number);
    }

    public function test_the_summary_line_and_amount_by_type(): void
    {
        $staff = $this->staff();
        $referral = $this->referral($this->booking(), $staff, 750000);
        $refund = $this->service->submitRefund($this->postedBooking(), 5000, null, $staff->id);

        $this->assertSame(750000.0, $referral->amount());
        $this->assertSame(5000.0, $refund->amount());
        $this->assertStringContainsString('Proses Referral', $referral->summaryLine());
        $this->assertStringContainsString('Rp750.000', $referral->summaryLine());
        $this->assertStringContainsString($staff->name, $referral->summaryLine());
    }
}
