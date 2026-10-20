<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceSetting;
use App\Models\FinanceTransaction;
use App\Models\FinanceTransactionApprovalRequest;
use App\Models\PaymentAccount;
use App\Models\Store;
use App\Models\User;
use App\Services\CashTransferService;
use App\Services\FinanceTransactionApprovalService;
use App\Services\FinancialStatementService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Keuangan Fase 5 (2026-11-08): API keuangan aplikasi staf, yaitu ringkasan kas toko, catat pengeluaran (kas kecil langsung tercatat sampai batas,
 * selain itu menjadi pengajuan), persetujuan, dan hitung kas. Waktu dibekukan: Rabu 7 Okt 2026.
 */
class StaffFinanceApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private User $clerk;
    private PaymentAccount $petty;
    private FinanceCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake(config('filament.default_filesystem_disk', 'public'));
        foreach (['kasir', 'store_manager', 'direksi'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        Carbon::setTestNow('2026-10-07 09:10:00');

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->clerk = $this->user('Kasir', 'kasir');
        $this->category = FinanceCategory::create(['name' => 'Beban ATK', 'type' => 'out', 'chart_of_account_id' => $this->acct('6410')->id, 'is_active' => true]);

        $this->petty = $this->pettyAccount('Kas Kecil A', '1103', $this->store, 1000000, 200000);
        app(CashTransferService::class)->create($this->acct('1101')->id, $this->petty->chart_of_account_id, 500000, now(), $this->store->id, null, null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function acct(string $code): ChartOfAccount
    {
        return ChartOfAccount::where('code', $code)->firstOrFail();
    }

    private function user(string $name, ?string $role = null, ?Store $store = null, array $extra = []): User
    {
        $user = User::create(array_merge(['name' => $name, 'email' => strtolower($name) . uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id], $extra));
        $role && $user->assignRole($role);

        return $user;
    }

    private function pettyAccount(string $name, string $code, Store $store, float $float, float $limit): PaymentAccount
    {
        $coa = ChartOfAccount::create([
            'code' => $code, 'name' => $name, 'type' => 'aset', 'normal_balance' => 'debit', 'parent_id' => $this->acct('1100')->id,
            'is_postable' => true, 'is_active' => true, 'is_cash' => true, 'is_contra' => false,
        ]);

        return PaymentAccount::create(['name' => $name, 'kind' => 'kas_kecil', 'chart_of_account_id' => $coa->id, 'store_id' => $store->id, 'float_amount' => $float, 'voucher_limit' => $limit, 'is_active' => true]);
    }

    private function balance(string $code): float
    {
        return app(FinancialStatementService::class)->balanceAsOf($this->acct($code), now());
    }

    private function expense(array $over = [], ?User $as = null, ?UploadedFile $receipt = null)
    {
        $body = array_merge(['finance_category_id' => $this->category->id, 'payment_account_id' => $this->petty->id, 'amount' => 100000, 'description' => 'Parkir'], $over);

        if ($receipt) {
            $body['receipt'] = $receipt;
        }

        return $this->actingAs($as ?? $this->clerk, 'api')->post('/api/staff/finance/expenses', $body, ['Accept' => 'application/json']);
    }

    // ------------------------------------------------------------- ringkasan dan akses

    public function test_overview_shows_only_the_staffs_own_store_accounts_and_flags(): void
    {
        $this->pettyAccount('Kas Kecil B', '1104', $this->otherStore, 500000, 100000);
        FinanceTransaction::create(['type' => 'out', 'finance_category_id' => $this->category->id, 'store_id' => $this->store->id, 'amount' => 75000, 'transaction_date' => now()->toDateString(), 'description' => 'x']);

        $response = $this->actingAs($this->clerk, 'api')->getJson('/api/staff/finance/overview')->assertSuccessful();

        $this->assertSame(['Kas Kecil A'], collect($response->json('accounts'))->pluck('name')->all());
        $this->assertSame(500000.0, (float) $response->json('accounts.0.balance'));
        $this->assertSame(500000.0, (float) $response->json('accounts.0.replenish_amount'), 'Plafon 1.000.000 dikurangi saldo 500.000.');
        $this->assertTrue($response->json('can_record_expense'));
        $this->assertFalse($response->json('can_approve'));
        $this->assertSame(75000.0, (float) $response->json('today.expenses_total'));
    }

    public function test_staff_without_finance_menus_are_denied_and_anonymous_requests_are_rejected(): void
    {
        $blocked = $this->user('Terblokir', 'kasir', null, ['menu_access' => ['BookingResource']]);

        $this->actingAs($blocked, 'api')->getJson('/api/staff/finance/overview')->assertStatus(403);
        $this->actingAs($blocked, 'api')->getJson('/api/staff/finance/expense-options')->assertStatus(403);
        $this->actingAs($blocked, 'api')->postJson('/api/staff/finance/expenses', [])->assertStatus(403);
        $this->actingAs($blocked, 'api')->getJson('/api/staff/finance/approvals')->assertStatus(403);
    }

    public function test_requests_without_a_token_are_unauthorized(): void
    {
        $this->getJson('/api/staff/finance/overview')->assertStatus(401);
    }

    public function test_expense_options_list_categories_and_own_store_accounts(): void
    {
        $response = $this->actingAs($this->clerk, 'api')->getJson('/api/staff/finance/expense-options')->assertSuccessful();

        $this->assertSame(['Beban ATK'], collect($response->json('categories'))->pluck('name')->all());
        $this->assertSame(['Kas Kecil A'], collect($response->json('accounts'))->pluck('name')->all());
    }

    // ------------------------------------------------------------- catat pengeluaran

    public function test_petty_cash_expense_within_the_limit_is_recorded_immediately_with_a_receipt(): void
    {
        $response = $this->expense([], null, UploadedFile::fake()->image('struk.jpg'))->assertStatus(201);

        $this->assertSame('recorded', $response->json('status'));
        $transaction = FinanceTransaction::firstOrFail();
        $this->assertNotNull($transaction->journal_entry_id);
        $this->assertSame($this->clerk->id, $transaction->created_by);
        $this->assertNotEmpty($transaction->receipt);
        Storage::disk(config('filament.default_filesystem_disk', 'public'))->assertExists($transaction->receipt);
        $this->assertEquals(400000, $this->balance('1103'));
    }

    public function test_expense_above_the_voucher_limit_becomes_an_approval_request(): void
    {
        $response = $this->expense(['amount' => 250000])->assertStatus(201);

        $this->assertSame('pending_approval', $response->json('status'));
        $this->assertSame(0, FinanceTransaction::count());
        $this->assertSame('pending_manager', FinanceTransactionApprovalRequest::firstOrFail()->status);
        $this->assertEquals(500000, $this->balance('1103'), 'Belum ada uang yang keluar.');
    }

    public function test_expense_beyond_the_petty_cash_balance_is_rejected_and_nothing_is_saved(): void
    {
        $this->petty->update(['voucher_limit' => 900000]);

        $this->expense(['amount' => 600000])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'tidak cukup'));

        $this->assertSame(0, FinanceTransaction::count());
        $this->assertSame(0, FinanceTransactionApprovalRequest::count());
    }

    public function test_another_stores_account_and_invalid_categories_are_rejected(): void
    {
        $theirs = $this->pettyAccount('Kas Kecil B', '1104', $this->otherStore, 500000, 100000);
        $group = FinanceCategory::create(['name' => 'Grup', 'type' => 'out', 'is_group' => true, 'is_active' => true]);

        $this->expense(['payment_account_id' => $theirs->id])->assertStatus(422);
        $this->expense(['finance_category_id' => $group->id])->assertStatus(422);
        $this->expense(['amount' => 0])->assertStatus(422);
        $this->expense(['amount' => 'abc'])->assertStatus(422);
        $this->assertSame(0, FinanceTransaction::count());
    }

    public function test_an_identical_expense_sent_twice_in_a_row_is_blocked(): void
    {
        $this->expense(['amount' => 50000])->assertStatus(201);
        $this->expense(['amount' => 50000])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'baru saja dikirim'));

        $this->assertSame(1, FinanceTransaction::count());
    }

    public function test_an_expense_dated_in_a_closed_period_fails_cleanly(): void
    {
        $closer = $this->user('Penutup', 'direksi');
        AccountingPeriod::create(['period_month' => '2026-09-01', 'closed_by' => $closer->id, 'closed_at' => now()]);

        $this->expense(['transaction_date' => '2026-09-15'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'ditutup'));

        $this->assertSame(0, FinanceTransaction::count(), 'Transaksi ikut dibatalkan.');
        $this->assertEquals(500000, $this->balance('1103'));
    }

    public function test_my_expenses_lists_recorded_and_pending_items(): void
    {
        $this->expense(['amount' => 50000])->assertStatus(201);
        $this->expense(['amount' => 250000])->assertStatus(201);

        $statuses = collect($this->actingAs($this->clerk, 'api')->getJson('/api/staff/finance/my-expenses')->assertSuccessful()->json('expenses'))->pluck('status')->all();

        $this->assertEqualsCanonicalizing(['recorded', 'pending_manager'], $statuses);
    }

    // ------------------------------------------------------------- persetujuan

    public function test_a_store_manager_sees_and_approves_only_their_own_stores_requests(): void
    {
        $manager = $this->user('Manajer', 'store_manager');
        $outsideManager = $this->user('Manajer B', 'store_manager', $this->otherStore);
        $this->expense(['amount' => 250000]);
        $request = FinanceTransactionApprovalRequest::firstOrFail();

        $this->actingAs($this->clerk, 'api')->getJson('/api/staff/finance/approvals')->assertStatus(403);
        $this->assertCount(0, $this->actingAs($outsideManager, 'api')->getJson('/api/staff/finance/approvals')->json('approvals'));

        $list = $this->actingAs($manager, 'api')->getJson('/api/staff/finance/approvals')->assertSuccessful()->json('approvals');
        $this->assertCount(1, $list);
        $this->assertSame('manager', $list[0]['stage']);
        $this->assertSame(250000.0, (float) $list[0]['amount']);

        $this->actingAs($manager, 'api')->postJson("/api/staff/finance/approvals/{$request->id}/approve")->assertSuccessful()->assertJsonPath('status', 'pending_direksi');
        $this->assertSame(0, FinanceTransaction::count(), 'Masih menunggu direksi.');
    }

    public function test_the_manager_limit_makes_manager_approval_final_from_the_app(): void
    {
        FinanceSetting::set(FinanceSetting::EXPENSE_MANAGER_LIMIT, 300000);
        $manager = $this->user('Manajer', 'store_manager');
        $this->expense(['amount' => 250000]);
        $request = FinanceTransactionApprovalRequest::firstOrFail();

        $this->actingAs($manager, 'api')->postJson("/api/staff/finance/approvals/{$request->id}/approve")->assertSuccessful()->assertJsonPath('status', 'approved');

        $this->assertSame(1, FinanceTransaction::count());
        $this->assertEquals(250000, abs($this->balance('1103') - 500000), 'Uang keluar 250.000 dari kas kecil.');
    }

    public function test_direksi_approves_the_final_stage_and_a_requester_cannot_approve_their_own(): void
    {
        $manager = $this->user('Manajer', 'store_manager');
        $direksi = $this->user('Direksi', 'direksi');
        $request = app(FinanceTransactionApprovalService::class)->submit([
            'type' => 'out', 'finance_category_id' => $this->category->id, 'store_id' => $this->store->id, 'amount' => 400000, 'transaction_date' => now()->toDateString(), 'description' => 'x',
        ], $manager);

        $this->assertCount(0, $this->actingAs($manager, 'api')->getJson('/api/staff/finance/approvals')->json('approvals'), 'Pengajuan sendiri tidak muncul.');
        $this->actingAs($manager, 'api')->postJson("/api/staff/finance/approvals/{$request->id}/approve")->assertStatus(422);

        $list = $this->actingAs($direksi, 'api')->getJson('/api/staff/finance/approvals')->json('approvals');
        $this->assertSame('direksi', $list[0]['stage']);

        $this->actingAs($direksi, 'api')->postJson("/api/staff/finance/approvals/{$request->id}/approve")->assertSuccessful()->assertJsonPath('status', 'approved');
        $this->assertSame(1, FinanceTransaction::count());
    }

    public function test_rejecting_records_the_note_and_an_unknown_request_is_404(): void
    {
        $manager = $this->user('Manajer', 'store_manager');
        $this->expense(['amount' => 250000]);
        $request = FinanceTransactionApprovalRequest::firstOrFail();

        $this->actingAs($manager, 'api')->postJson("/api/staff/finance/approvals/{$request->id}/reject", ['note' => 'Tidak ada nota'])->assertSuccessful();

        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertSame('Tidak ada nota', $request->fresh()->rejection_note);
        $this->actingAs($manager, 'api')->postJson('/api/staff/finance/approvals/99999/approve')->assertStatus(404);
    }

    // ------------------------------------------------------------- hitung kas

    public function test_cash_count_via_the_app_records_difference_and_enforces_rules(): void
    {
        $theirs = $this->pettyAccount('Kas Kecil B', '1104', $this->otherStore, 500000, 100000);

        $this->actingAs($this->clerk, 'api')->postJson('/api/staff/finance/cash-counts', ['payment_account_id' => $this->petty->id, 'counted_amount' => 480000])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains(strtolower($m), 'selisih'));

        $this->actingAs($this->clerk, 'api')->postJson('/api/staff/finance/cash-counts', ['payment_account_id' => $theirs->id, 'counted_amount' => 0])->assertStatus(422);

        $created = $this->actingAs($this->clerk, 'api')->postJson('/api/staff/finance/cash-counts', ['payment_account_id' => $this->petty->id, 'counted_amount' => 480000, 'notes' => 'Parkir belum dicatat'])
            ->assertStatus(201);

        $this->assertSame(-20000.0, (float) $created->json('count.difference'));

        $this->actingAs($this->clerk, 'api')->postJson('/api/staff/finance/cash-counts', ['payment_account_id' => $this->petty->id, 'counted_amount' => 500000])->assertStatus(422);

        $list = $this->actingAs($this->clerk, 'api')->getJson('/api/staff/finance/cash-counts')->assertSuccessful();
        $this->assertTrue($list->json('accounts.0.counted_today'));
        $this->assertCount(1, $list->json('counts'));
    }
}
