<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\FinanceCategory;
use App\Models\Store;
use App\Models\User;
use App\Services\FinanceTransactionApprovalService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Audit framework 2026-09-14/Fase 4 (2026-09-15) -- approval berjenjang
 * pengeluaran Transaksi Keuangan: staff -> store_manager -> direksi.
 */
class FinanceTransactionApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private FinanceCategory $expenseCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);

        Role::findOrCreate('store_manager', 'web');
        Role::findOrCreate('direksi', 'web');

        $this->store = Store::create(['name' => 'Toko Test', 'is_active' => true]);

        $account = ChartOfAccount::where('code', '6510')->firstOrFail();
        $this->expenseCategory = FinanceCategory::create([
            'name' => 'Beban Test',
            'type' => 'out',
            'chart_of_account_id' => $account->id,
            'is_active' => true,
        ]);
    }

    private function makeStaff(): User
    {
        return User::create([
            'name' => 'Staff Toko',
            'email' => 'staff-' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'store_id' => $this->store->id,
        ]);
    }

    private function makeStoreManager(): User
    {
        $user = User::create([
            'name' => 'Store Manager',
            'email' => 'manager-' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'store_id' => $this->store->id,
        ]);
        $user->assignRole('store_manager');

        return $user;
    }

    private function makeDireksi(): User
    {
        $user = User::create([
            'name' => 'Direksi',
            'email' => 'direksi-' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole('direksi');

        return $user;
    }

    private function payload(): array
    {
        return [
            'type' => 'out',
            'finance_category_id' => $this->expenseCategory->id,
            'store_id' => $this->store->id,
            'amount' => 500_000,
            'transaction_date' => now()->toDateString(),
            'description' => 'Beli ATK',
        ];
    }

    public function test_staff_submission_starts_at_pending_manager(): void
    {
        $staff = $this->makeStaff();

        $request = app(FinanceTransactionApprovalService::class)->submit($this->payload(), $staff);

        $this->assertEquals('pending_manager', $request->status);
        $this->assertEquals($staff->id, $request->requested_by);
    }

    public function test_store_manager_self_submission_skips_to_pending_direksi(): void
    {
        $manager = $this->makeStoreManager();

        $request = app(FinanceTransactionApprovalService::class)->submit($this->payload(), $manager);

        $this->assertEquals('pending_direksi', $request->status);
    }

    public function test_full_approval_flow_creates_finance_transaction_and_posts_journal(): void
    {
        $staff = $this->makeStaff();
        $manager = $this->makeStoreManager();
        $direksi = $this->makeDireksi();
        $service = app(FinanceTransactionApprovalService::class);

        $request = $service->submit($this->payload(), $staff);
        $service->approveByManager($request, $manager);
        $this->assertEquals('pending_direksi', $request->fresh()->status);

        $transaction = $service->approveByDireksi($request->fresh(), $direksi);

        $this->assertEquals('approved', $request->fresh()->status);
        $this->assertEquals(500_000, (float) $transaction->amount);
        $this->assertNotNull($transaction->journal_entry_id);
        $this->assertEquals($staff->id, $transaction->created_by, 'Pemohon asli harus tetap tercatat sebagai created_by, bukan approver.');
    }

    public function test_manager_from_different_store_cannot_approve(): void
    {
        $staff = $this->makeStaff();
        $otherStore = Store::create(['name' => 'Toko Lain', 'is_active' => true]);
        $otherManager = User::create([
            'name' => 'Manager Toko Lain',
            'email' => 'other-manager-' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'store_id' => $otherStore->id,
        ]);
        $otherManager->assignRole('store_manager');

        $service = app(FinanceTransactionApprovalService::class);
        $request = $service->submit($this->payload(), $staff);

        $this->expectException(RuntimeException::class);

        $service->approveByManager($request, $otherManager);
    }

    public function test_non_direksi_cannot_approve_second_stage(): void
    {
        $staff = $this->makeStaff();
        $manager = $this->makeStoreManager();
        $service = app(FinanceTransactionApprovalService::class);

        $request = $service->submit($this->payload(), $staff);
        $service->approveByManager($request, $manager);

        $this->expectException(RuntimeException::class);

        $service->approveByDireksi($request->fresh(), $manager);
    }

    public function test_rejection_marks_request_final(): void
    {
        $staff = $this->makeStaff();
        $manager = $this->makeStoreManager();
        $service = app(FinanceTransactionApprovalService::class);

        $request = $service->submit($this->payload(), $staff);
        $service->reject($request, $manager, 'Tidak sesuai anggaran');

        $this->assertEquals('rejected', $request->fresh()->status);

        $this->expectException(RuntimeException::class);
        $service->approveByManager($request->fresh(), $manager);
    }
}
