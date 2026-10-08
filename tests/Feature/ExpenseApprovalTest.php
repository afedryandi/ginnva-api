<?php

namespace Tests\Feature;

use App\Filament\Resources\FinanceTransactionApprovalRequestResource;
use App\Filament\Resources\FinanceTransactionApprovalRequestResource\Pages\ListFinanceTransactionApprovalRequests;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\FinanceTransactionApprovalRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\FinanceTransactionApprovalService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Persetujuan Pengeluaran (melengkapi FinanceTransactionApprovalServiceTest): validasi pengajuan di server,
 * tahap Store Manager lalu Direksi, pemisahan tugas, validasi ulang saat disetujui (kategori/toko/periode),
 * penolakan & pembatalan (nota dihapus), ajukan ulang, pengingat harian, dan layar admin (visibilitas per
 * peran, matriks tombol, tab/filter/pencarian, aksi tabel, badge).
 */
class ExpenseApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private FinanceCategory $expense;
    private FinanceTransactionApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('public');
        foreach (['kasir', 'store_manager', 'spv_finance', 'direksi'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->expense = FinanceCategory::create(['name' => 'Beban Listrik', 'type' => 'out', 'chart_of_account_id' => ChartOfAccount::where('code', '6510')->value('id'), 'is_active' => true]);
        $this->service = app(FinanceTransactionApprovalService::class);
    }

    private function user(string $role, ?Store $store = null, bool $withStore = true): User
    {
        return tap(User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => $withStore ? ($store ?? $this->store)->id : null]), fn (User $u) => $u->assignRole($role));
    }

    private function staff(?Store $store = null): User
    {
        return $this->user('kasir', $store);
    }

    private function manager(?Store $store = null): User
    {
        return $this->user('store_manager', $store);
    }

    private function admin(): User
    {
        return $this->user('super_admin', null, false);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'out', 'finance_category_id' => $this->expense->id, 'store_id' => $this->store->id,
            'amount' => 500000, 'transaction_date' => now()->toDateString(), 'description' => 'Tagihan listrik',
        ], $overrides);
    }

    private function submitAs(User $user, array $overrides = []): FinanceTransactionApprovalRequest
    {
        return $this->service->submit($this->payload($overrides), $user);
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

    public function test_a_staff_submission_waits_for_the_store_manager_and_a_manager_submission_skips_to_direksi(): void
    {
        $staff = $this->staff();
        $manager = $this->manager();

        $byStaff = $this->submitAs($staff);
        $byManager = $this->submitAs($manager);

        $this->assertSame('pending_manager', $byStaff->status);
        $this->assertMatchesRegularExpression('/^EXP-\d{6}-[A-Z0-9]{4}$/', $byStaff->request_number);
        $this->assertSame($this->store->id, $byStaff->store_id);
        $this->assertSame('pending_direksi', $byManager->status);
    }

    public function test_non_full_access_requesters_are_pinned_to_their_own_store_and_the_payload_is_whitelisted(): void
    {
        $request = $this->submitAs($this->staff(), ['store_id' => $this->otherStore->id, 'journal_entry_id' => 99, 'created_by' => 1]);

        $this->assertSame($this->store->id, $request->payload['store_id']);
        $this->assertSame($this->store->id, $request->store_id);
        $this->assertSame(['type', 'finance_category_id', 'store_id', 'amount', 'transaction_date', 'description'], array_keys($request->payload));
    }

    public function test_submission_validation(): void
    {
        $staff = $this->staff();
        $noAccount = FinanceCategory::create(['name' => 'Tanpa Akun', 'type' => 'out', 'is_active' => true]);
        $inactive = FinanceCategory::create(['name' => 'Nonaktif', 'type' => 'out', 'chart_of_account_id' => ChartOfAccount::where('code', '6510')->value('id'), 'is_active' => false]);
        $income = FinanceCategory::create(['name' => 'Pemasukan', 'type' => 'in', 'chart_of_account_id' => ChartOfAccount::where('code', '4400')->value('id'), 'is_active' => true]);
        $group = FinanceCategory::create(['name' => 'Grup Beban', 'type' => 'out', 'is_group' => true, 'is_active' => true]);

        $this->assertRefused(fn () => $this->submitAs($staff, ['finance_category_id' => 999999]), 'Kategori tidak ditemukan');
        $this->assertRefused(fn () => $this->submitAs($staff, ['finance_category_id' => $inactive->id]), 'nonaktif');
        $this->assertRefused(fn () => $this->submitAs($staff, ['finance_category_id' => $group->id]), 'berupa grup');
        $this->assertRefused(fn () => $this->submitAs($staff, ['finance_category_id' => $noAccount->id]), 'belum terhubung ke akun');
        $this->assertRefused(fn () => $this->submitAs($staff, ['finance_category_id' => $income->id]), 'hanya untuk transaksi pengeluaran');
        $this->assertRefused(fn () => $this->submitAs($staff, ['type' => 'in']), 'hanya untuk transaksi pengeluaran');
        $this->assertRefused(fn () => $this->submitAs($staff, ['amount' => 0]), 'lebih dari 0');
        $this->assertRefused(fn () => $this->submitAs($staff, ['amount' => 'abc']), 'lebih dari 0');
        $this->assertSame(0, FinanceTransactionApprovalRequest::count());

        $this->store->update(['is_active' => false]);
        $this->assertRefused(fn () => $this->submitAs($staff), 'Toko tidak ditemukan atau sudah nonaktif');
    }

    public function test_next_approvers_are_notified_by_stage_with_a_fallback_to_full_access(): void
    {
        $staff = $this->staff();
        $sameStoreManager = $this->manager();
        $otherStoreManager = $this->manager($this->otherStore);
        $admin = $this->admin();

        $this->submitAs($staff);
        $this->assertSame(1, $sameStoreManager->notifications()->count());
        $this->assertSame(0, $otherStoreManager->notifications()->count());
        $this->assertSame(0, $admin->notifications()->count());

        $this->submitAs($this->staff($this->otherStore), ['store_id' => $this->otherStore->id]);
        $this->assertSame(1, $otherStoreManager->notifications()->count());

        $lonely = Store::create(['city' => 'Medan', 'address' => 'Jl. C', 'name' => 'Toko C', 'is_active' => true]);
        $this->submitAs($this->staff($lonely));
        $this->assertSame(1, $admin->notifications()->count(), 'Toko tanpa store manager: diteruskan ke full-access.');

        $this->submitAs($sameStoreManager);
        $this->assertSame(2, $admin->notifications()->count(), 'Pengajuan manager langsung ke direksi.');
    }

    // ------------------------------------------------------------- tahap Store Manager

    public function test_store_manager_approval_moves_to_direksi_and_is_restricted_to_the_same_store(): void
    {
        $request = $this->submitAs($this->staff());
        $admin = $this->admin();

        foreach ([$this->manager($this->otherStore), $this->staff(), $this->user('spv_finance')] as $outsider) {
            $this->assertRefused(fn () => $this->service->approveByManager($request, $outsider), 'Cuma Store Manager toko yang sama');
        }
        $this->assertSame('pending_manager', $request->fresh()->status);

        $manager = $this->manager();
        $this->service->approveByManager($request, $manager);

        $request->refresh();
        $this->assertSame('pending_direksi', $request->status);
        $this->assertSame($manager->id, $request->manager_approved_by);
        $this->assertNotNull($request->manager_approved_at);
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertRefused(fn () => $this->service->approveByManager($request, $manager), 'bukan lagi menunggu persetujuan Store Manager');
    }

    public function test_a_full_access_user_may_also_approve_the_manager_stage_but_never_their_own_request(): void
    {
        $staff = $this->staff();
        $admin = $this->admin();
        $this->service->approveByManager($this->submitAs($staff), $admin);

        $own = $this->submitAs($staff);
        $own->update(['requested_by' => $admin->id]);
        $this->assertRefused(fn () => $this->service->approveByManager($own->fresh(), $admin), 'tidak boleh menyetujui pengajuan yang Anda ajukan sendiri');
    }

    // ------------------------------------------------------------- tahap Direksi

    public function test_direksi_approval_creates_the_transaction_and_journal_and_notifies_the_requester(): void
    {
        $staff = $this->staff();
        $direksi = $this->user('direksi', null, false);
        $request = $this->submitAs($staff);
        $this->service->approveByManager($request, $this->manager());

        $transaction = $this->service->approveByDireksi($request->fresh(), $direksi);

        $this->assertInstanceOf(FinanceTransaction::class, $transaction);
        $this->assertEquals(500000, $transaction->amount);
        $this->assertSame($staff->id, $transaction->created_by);
        $this->assertNotNull($transaction->fresh()->journal_entry_id);
        $this->assertTrue($transaction->fresh()->journalEntry->isPosted());
        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($direksi->id, $request->direksi_approved_by);
        $this->assertSame($transaction->id, $request->finance_transaction_id);
        $this->assertStringContainsString('Pengeluaran disetujui', $staff->notifications()->first()->data['title']);
    }

    public function test_direksi_stage_rules(): void
    {
        $staff = $this->staff();
        $direksi = $this->user('direksi', null, false);
        $request = $this->submitAs($staff);

        $this->assertRefused(fn () => $this->service->approveByDireksi($request, $direksi), 'bukan lagi menunggu persetujuan Direksi');

        $this->service->approveByManager($request, $this->manager());
        $this->assertRefused(fn () => $this->service->approveByDireksi($request->fresh(), $this->manager()), 'Cuma Direksi/Super Admin');
        $this->assertRefused(fn () => $this->service->approveByDireksi($request->fresh(), $this->user('spv_finance')), 'Cuma Direksi/Super Admin');

        $this->service->approveByDireksi($request->fresh(), $direksi);
        $this->assertRefused(fn () => $this->service->approveByDireksi($request->fresh(), $direksi), 'bukan lagi menunggu persetujuan Direksi');
        $this->assertSame(1, FinanceTransaction::count(), 'Satu pengajuan = satu transaksi.');
    }

    public function test_a_requester_who_became_direksi_cannot_approve_their_own_request(): void
    {
        $requester = $this->staff();
        $request = $this->submitAs($requester);
        $this->service->approveByManager($request, $this->manager());
        $requester->syncRoles(['direksi']);

        $this->assertRefused(fn () => $this->service->approveByDireksi($request->fresh(), $requester->fresh()), 'tidak boleh menyetujui pengajuan yang Anda ajukan sendiri');
    }

    public function test_approval_revalidates_category_store_and_period_and_leaves_no_transaction_behind(): void
    {
        $direksi = $this->user('direksi', null, false);
        $request = $this->submitAs($this->staff());
        $this->service->approveByManager($request, $this->manager());

        $this->expense->update(['is_active' => false]);
        $this->assertRefused(fn () => $this->service->approveByDireksi($request->fresh(), $direksi), 'Kategori pada pengajuan ini sudah tidak aktif');
        $this->expense->update(['is_active' => true]);

        $this->store->update(['is_active' => false]);
        $this->assertRefused(fn () => $this->service->approveByDireksi($request->fresh(), $direksi), 'Toko pada pengajuan ini');
        $this->store->update(['is_active' => true]);

        AccountingPeriod::create(['period_month' => now()->startOfMonth()->toDateString(), 'closed_by' => $direksi->id, 'closed_at' => now()]);
        $this->assertRefused(fn () => $this->service->approveByDireksi($request->fresh(), $direksi), 'ditutup');

        $this->assertSame('pending_direksi', $request->fresh()->status);
        $this->assertSame(0, FinanceTransaction::count());
        $this->assertSame(0, DB::table('journal_entries')->where('reference_type', 'finance_transaction')->count());
    }

    // ------------------------------------------------------------- tolak, batal, ajukan ulang

    public function test_reject_authorization_by_stage(): void
    {
        $staff = $this->staff();
        $manager = $this->manager();
        $atManager = $this->submitAs($staff);

        $this->assertRefused(fn () => $this->service->reject($atManager, $this->manager($this->otherStore), 'x'), 'tidak berwenang');
        $this->assertRefused(fn () => $this->service->reject($atManager, $this->staff(), 'x'), 'tidak berwenang');
        $this->service->reject($atManager, $manager, 'Nota tidak jelas');
        $this->assertSame('rejected', $atManager->fresh()->status);

        $atDireksi = $this->submitAs($staff);
        $this->service->approveByManager($atDireksi, $manager);
        $this->assertRefused(fn () => $this->service->reject($atDireksi->fresh(), $manager, 'x'), 'tidak berwenang');
        $this->service->reject($atDireksi->fresh(), $this->admin(), 'Terlalu besar');
        $this->assertSame('rejected', $atDireksi->fresh()->status);
        $this->assertRefused(fn () => $this->service->reject($atDireksi->fresh(), $this->admin(), 'lagi'), 'sudah final');
    }

    public function test_rejecting_records_the_decision_notifies_and_deletes_the_receipt(): void
    {
        $staff = $this->staff();
        Storage::disk('public')->put('receipts/nota.jpg', 'x');
        $request = $this->service->submit($this->payload(['receipt' => 'receipts/nota.jpg']), $staff);
        $admin = $this->admin();

        $this->service->reject($request, $admin, 'Dobel input');

        $request->refresh();
        $this->assertSame($admin->id, $request->rejected_by);
        $this->assertSame('Dobel input', $request->rejection_note);
        Storage::disk('public')->assertMissing('receipts/nota.jpg');
        $this->assertStringContainsString('ditolak', $staff->notifications()->first()->data['title']);
        $this->assertStringContainsString('Dobel input', $staff->notifications()->first()->data['body']);
    }

    public function test_only_the_requester_can_cancel_while_pending_and_the_receipt_goes(): void
    {
        $staff = $this->staff();
        Storage::disk('public')->put('receipts/nota2.jpg', 'x');
        $request = $this->service->submit($this->payload(['receipt' => 'receipts/nota2.jpg']), $staff);

        $this->assertRefused(fn () => $this->service->cancel($request, $this->staff()), 'Cuma pengaju');
        $this->assertRefused(fn () => $this->service->cancel($request, $this->admin()), 'Cuma pengaju');

        $this->service->cancel($request, $staff);
        $this->assertSame('cancelled', $request->fresh()->status);
        Storage::disk('public')->assertMissing('receipts/nota2.jpg');
        $this->assertRefused(fn () => $this->service->cancel($request->fresh(), $staff), 'sudah diputuskan');
        $this->assertRefused(fn () => $this->service->approveByManager($request->fresh(), $this->manager()), 'bukan lagi menunggu');

        $approved = $this->submitAs($staff);
        $this->service->approveByManager($approved, $this->manager());
        $this->service->approveByDireksi($approved->fresh(), $this->user('direksi', null, false));
        $this->assertRefused(fn () => $this->service->cancel($approved->fresh(), $staff), 'sudah diputuskan');
    }

    public function test_resubmit_creates_a_fresh_request_once_without_the_receipt(): void
    {
        $staff = $this->staff();
        Storage::disk('public')->put('receipts/nota3.jpg', 'x');
        $original = $this->service->submit($this->payload(['receipt' => 'receipts/nota3.jpg', 'amount' => 123000]), $staff);

        $this->assertRefused(fn () => $this->service->resubmit($original, $staff), 'ditolak/dibatalkan');

        $this->service->reject($original, $this->admin(), 'Cek ulang');
        $this->assertRefused(fn () => $this->service->resubmit($original->fresh(), $this->staff()), 'pengaju asli');

        $new = $this->service->resubmit($original->fresh(), $staff);

        $this->assertSame('pending_manager', $new->status);
        $this->assertEquals(123000, $new->payload['amount']);
        $this->assertArrayNotHasKey('receipt', $new->payload);
        $this->assertNotNull($original->fresh()->resubmitted_at);
        $this->assertRefused(fn () => $this->service->resubmit($original->fresh(), $staff), 'sudah pernah diajukan ulang');
    }

    public function test_the_stuck_notice_reaches_the_requester(): void
    {
        $staff = $this->staff();
        $request = $this->submitAs($staff);

        $this->service->notifyRequesterStuck($request, 'Periode sudah ditutup');

        $this->assertSame('Pengajuan pengeluaran Anda tertahan', $staff->notifications()->first()->data['title']);
    }

    // ------------------------------------------------------------- pengingat harian

    public function test_the_reminder_targets_approvers_by_stage_and_ignores_fresh_and_decided_requests(): void
    {
        $staff = $this->staff();
        $manager = $this->manager();
        $otherManager = $this->manager($this->otherStore);
        $admin = $this->admin();
        $atManager = $this->submitAs($staff);
        $atDireksi = $this->submitAs($manager);
        $fresh = $this->submitAs($staff);
        $decided = $this->submitAs($staff);
        $this->service->reject($decided, $admin, 'x');
        DB::table('finance_expense_approvals')->whereIn('id', [$atManager->id, $atDireksi->id, $decided->id])->update(['created_at' => now()->subDays(3)]);
        DB::table('notifications')->delete();

        $this->artisan('finance:remind-pending-approvals')->assertSuccessful();

        $this->assertSame(1, $manager->notifications()->count());
        $this->assertStringContainsString('1 pengajuan', $manager->notifications()->first()->data['title'], 'Manager hanya soal tahapnya.');
        $this->assertStringContainsString('2 pengajuan', $admin->notifications()->first()->data['title'], 'Full-access: tahap manager + tahap direksi.');
        $this->assertSame(0, $otherManager->notifications()->count());
        $this->assertSame(0, $staff->notifications()->count());
    }

    public function test_the_reminder_is_quiet_when_nothing_is_stale(): void
    {
        $this->submitAs($this->staff());

        $this->artisan('finance:remind-pending-approvals')->expectsOutput('Tidak ada pengajuan yang menggantung.')->assertSuccessful();
    }

    // ------------------------------------------------------------- layar admin

    private function listFor(User $user, string $tab = 'semua')
    {
        $this->actingAs($user, 'web');

        return Livewire::test(ListFinanceTransactionApprovalRequests::class)->set('activeTab', $tab);
    }

    public function test_access_follows_staff_area_and_the_transaction_menu(): void
    {
        $this->actingAs($this->staff(), 'web');
        $this->assertTrue(FinanceTransactionApprovalRequestResource::canViewAny());
        $this->assertFalse(FinanceTransactionApprovalRequestResource::canCreate());
        $this->assertFalse(FinanceTransactionApprovalRequestResource::canDelete(new FinanceTransactionApprovalRequest()));

        $noMenu = $this->user('kasir');
        $noMenu->update(['menu_access' => ['BookingResource']]);
        $this->actingAs($noMenu->fresh(), 'web');
        $this->assertFalse(FinanceTransactionApprovalRequestResource::canViewAny());
    }

    public function test_visibility_per_role_and_badge_counts(): void
    {
        $staffA = $this->staff();
        $staffA2 = $this->staff();
        $staffB = $this->staff($this->otherStore);
        $mine = $this->submitAs($staffA);
        $sameStore = $this->submitAs($staffA2);
        $otherStore = $this->submitAs($staffB);
        $managerA = $this->manager();
        $this->service->approveByManager($sameStore, $managerA);

        $this->listFor($staffA)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$sameStore, $otherStore]);
        $this->listFor($managerA)->assertCanSeeTableRecords([$mine, $sameStore])->assertCanNotSeeTableRecords([$otherStore]);
        $this->listFor($this->admin())->assertCanSeeTableRecords([$mine, $sameStore, $otherStore]);

        $this->actingAs($this->admin(), 'web');
        $this->assertSame('1', FinanceTransactionApprovalRequestResource::getNavigationBadge(), 'Direksi: hanya yang menunggu direksi.');
        $this->actingAs($managerA, 'web');
        $this->assertSame('1', FinanceTransactionApprovalRequestResource::getNavigationBadge(), 'Manager: yang menunggu tahapnya di tokonya.');
        $this->actingAs($staffA, 'web');
        $this->assertNull(FinanceTransactionApprovalRequestResource::getNavigationBadge());
    }

    public function test_tabs_filter_search_and_amount_column(): void
    {
        $staff = $this->staff();
        $admin = $this->admin();
        $pending = $this->submitAs($staff, ['amount' => 111000]);
        $approved = $this->submitAs($staff, ['amount' => 222000]);
        $this->service->approveByManager($approved, $this->manager());
        $this->service->approveByDireksi($approved->fresh(), $this->user('direksi', null, false));
        $rejected = $this->submitAs($staff, ['amount' => 333000]);
        $this->service->reject($rejected, $admin, 'x');
        $cancelled = $this->submitAs($staff, ['amount' => 444000]);
        $this->service->cancel($cancelled, $staff);
        $other = FinanceCategory::create(['name' => 'Beban Air', 'type' => 'out', 'chart_of_account_id' => ChartOfAccount::where('code', '6510')->value('id'), 'is_active' => true]);
        $water = $this->submitAs($staff, ['amount' => 555000, 'finance_category_id' => $other->id]);

        $this->listFor($admin, 'menunggu')->assertCanSeeTableRecords([$pending, $water])->assertCanNotSeeTableRecords([$approved, $rejected, $cancelled]);
        $this->listFor($admin, 'disetujui')->assertCanSeeTableRecords([$approved])->assertCanNotSeeTableRecords([$pending]);
        $this->listFor($admin, 'ditolak')->assertCanSeeTableRecords([$rejected, $cancelled])->assertCanNotSeeTableRecords([$pending, $approved]);
        $this->listFor($admin)->filterTable('status', 'rejected')->assertCanSeeTableRecords([$rejected])->assertCanNotSeeTableRecords([$pending]);
        $this->listFor($admin)->assertTableColumnStateSet('amount', 'Rp111.000', $pending);
        $this->listFor($admin)->searchTable('Beban Air')->assertCanSeeTableRecords([$water])->assertCanNotSeeTableRecords([$pending]);
        $this->listFor($admin)->searchTable($pending->request_number)->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$water]);
    }

    public function test_action_buttons_follow_stage_role_and_store(): void
    {
        $staff = $this->staff();
        $managerA = $this->manager();
        $managerB = $this->manager($this->otherStore);
        $admin = $this->admin();
        $atManager = $this->submitAs($staff);
        $atDireksi = $this->submitAs($staff);
        $this->service->approveByManager($atDireksi, $managerA);

        $this->listFor($managerA, 'menunggu')
            ->assertTableActionVisible('approve_manager', $atManager)->assertTableActionVisible('reject', $atManager)
            ->assertTableActionHidden('approve_manager', $atDireksi)->assertTableActionHidden('approve_direksi', $atDireksi)->assertTableActionHidden('reject', $atDireksi);
        $this->listFor($managerB, 'menunggu')->assertTableActionHidden('approve_manager', $atManager);
        $this->listFor($admin, 'menunggu')
            ->assertTableActionVisible('approve_manager', $atManager)->assertTableActionHidden('approve_direksi', $atManager)
            ->assertTableActionVisible('approve_direksi', $atDireksi)->assertTableActionVisible('reject', $atDireksi);
        $this->listFor($staff, 'menunggu')
            ->assertTableActionHidden('approve_manager', $atManager)->assertTableActionHidden('approve_direksi', $atDireksi)->assertTableActionHidden('reject', $atManager)
            ->assertTableActionVisible('cancelRequest', $atManager);
    }

    public function test_two_stage_approval_from_the_table(): void
    {
        $staff = $this->staff();
        $request = $this->submitAs($staff);

        $this->listFor($this->manager(), 'menunggu')->callTableAction('approve_manager', $request);
        $this->assertSame('pending_direksi', $request->fresh()->status);

        $this->listFor($this->admin(), 'menunggu')->callTableAction('approve_direksi', $request->fresh());

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(1, FinanceTransaction::count());
    }

    public function test_a_failing_direksi_approval_from_the_table_stays_pending_and_alerts_the_requester(): void
    {
        $staff = $this->staff();
        $request = $this->submitAs($staff);
        $this->service->approveByManager($request, $this->manager());
        AccountingPeriod::create(['period_month' => now()->startOfMonth()->toDateString(), 'closed_by' => $this->admin()->id, 'closed_at' => now()]);

        $this->listFor($this->admin(), 'menunggu')->callTableAction('approve_direksi', $request->fresh());

        $this->assertSame('pending_direksi', $request->fresh()->status);
        $this->assertSame(0, FinanceTransaction::count());
        $this->assertSame('Pengajuan pengeluaran Anda tertahan', $staff->notifications()->first()->data['title']);
    }

    public function test_reject_cancel_and_resubmit_from_the_table(): void
    {
        $staff = $this->staff();
        $request = $this->submitAs($staff);

        $this->listFor($this->manager(), 'menunggu')
            ->callTableAction('reject', $request, data: ['rejection_note' => ''])
            ->assertHasTableActionErrors(['rejection_note' => 'required']);
        $this->assertSame('pending_manager', $request->fresh()->status);

        $this->listFor($this->manager(), 'menunggu')->callTableAction('reject', $request, data: ['rejection_note' => 'Nota kurang']);
        $this->assertSame('rejected', $request->fresh()->status);

        $this->listFor($staff)->assertTableActionVisible('resubmit', $request->fresh())->callTableAction('resubmit', $request->fresh());
        $this->assertSame(2, FinanceTransactionApprovalRequest::count());
        $this->listFor($staff)->assertTableActionHidden('resubmit', $request->fresh());

        $new = FinanceTransactionApprovalRequest::where('status', 'pending_manager')->firstOrFail();
        $this->listFor($staff, 'menunggu')->callTableAction('cancelRequest', $new);
        $this->assertSame('cancelled', $new->fresh()->status);
    }

    public function test_receipt_link_only_exists_when_a_receipt_was_uploaded_and_the_detail_shows_the_history(): void
    {
        $staff = $this->staff();
        $withReceipt = $this->service->submit($this->payload(['receipt' => 'receipts/ada.jpg']), $staff);
        $without = $this->submitAs($staff);
        $admin = $this->admin();
        $this->service->reject($without, $admin, 'Dokumen kurang');

        $this->listFor($admin)->assertTableActionVisible('viewReceipt', $withReceipt)->assertTableActionHidden('viewReceipt', $without);
        $this->listFor($admin)->mountTableAction('view', $without->fresh())->assertSee('Dokumen kurang')->assertSee($without->request_number)->assertSee($admin->name);
    }
}
