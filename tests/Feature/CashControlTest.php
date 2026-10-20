<?php

namespace Tests\Feature;

use App\Filament\Pages\BudgetVsActualReport;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Resources\CashCountResource\Pages\ListCashCounts;
use App\Filament\Resources\FinanceTransactionResource\Pages\CreateFinanceTransaction;
use App\Models\Budget;
use App\Models\CashCount;
use App\Models\ChartOfAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceSetting;
use App\Models\FinanceTransaction;
use App\Models\PaymentAccount;
use App\Models\Store;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\CashCountService;
use App\Services\CashTransferService;
use App\Services\FinanceTransactionApprovalService;
use App\Services\FinanceTransactionPostingService;
use App\Services\FinancialStatementService;
use App\Services\PettyCashService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Keuangan Fase 4 (2026-11-07): batas persetujuan Store Manager, kas kecil (plafon, batas per bukti, saldo, isi ulang), hitung kas harian dan
 * jurnal selisih, serta anggaran vs realisasi. Waktu dibekukan: Rabu 7 Okt 2026.
 */
class CashControlTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private FinanceCategory $expense;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['store_manager', 'direksi', 'super_admin', 'kasir'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        Carbon::setTestNow('2026-10-07 09:10:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->expense = FinanceCategory::create(['name' => 'Beban ATK', 'type' => 'out', 'chart_of_account_id' => $this->acct('6410')->id, 'is_active' => true]);
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

    private function user(string $name, ?string $role = null, ?Store $store = null): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower($name) . uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id]);
        $role && $user->assignRole($role);

        return $user;
    }

    private function balance(string $code): float
    {
        return app(FinancialStatementService::class)->balanceAsOf($this->acct($code), now());
    }

    private function cashCoa(string $code, string $name): ChartOfAccount
    {
        return ChartOfAccount::create([
            'code' => $code, 'name' => $name, 'type' => 'aset', 'normal_balance' => 'debit', 'parent_id' => $this->acct('1100')->id,
            'is_postable' => true, 'is_active' => true, 'is_cash' => true, 'is_contra' => false,
        ]);
    }

    private function pettyAccount(array $over = []): PaymentAccount
    {
        return PaymentAccount::create(array_merge([
            'name' => 'Kas Kecil Toko A', 'kind' => 'kas_kecil', 'chart_of_account_id' => $this->cashCoa('1103', 'Kas Kecil A')->id, 'store_id' => $this->store->id,
            'float_amount' => 1000000, 'voucher_limit' => 200000, 'is_active' => true,
        ], $over));
    }

    private function fund(PaymentAccount $petty, float $amount): void
    {
        app(CashTransferService::class)->create($this->acct('1101')->id, $petty->chart_of_account_id, $amount, now(), $this->store->id, null, null);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'type' => 'out', 'finance_category_id' => $this->expense->id, 'store_id' => $this->store->id,
            'amount' => 500000, 'transaction_date' => now()->toDateString(), 'description' => 'Beli ATK',
        ], $over);
    }

    // ------------------------------------------------------------- batas persetujuan Store Manager

    public function test_without_a_limit_every_amount_still_goes_to_direksi(): void
    {
        $service = app(FinanceTransactionApprovalService::class);
        $request = $service->submit($this->payload(['amount' => 50000]), $this->user('Staf'));

        $service->approveByManager($request, $this->user('Manajer', 'store_manager'));

        $this->assertSame('pending_direksi', $request->fresh()->status);
        $this->assertSame(0, FinanceTransaction::count());
    }

    public function test_manager_approval_is_final_up_to_the_limit_and_above_it_still_needs_direksi(): void
    {
        FinanceSetting::set(FinanceSetting::EXPENSE_MANAGER_LIMIT, 300000);
        $service = app(FinanceTransactionApprovalService::class);
        $staff = $this->user('Staf');
        $manager = $this->user('Manajer', 'store_manager');

        $small = $service->submit($this->payload(['amount' => 250000]), $staff);
        $service->approveByManager($small, $manager);

        $this->assertSame('approved', $small->fresh()->status);
        $this->assertNull($small->fresh()->direksi_approved_by, 'Tidak ada persetujuan direksi.');
        $this->assertSame($manager->id, $small->fresh()->manager_approved_by);
        $this->assertSame(1, FinanceTransaction::count());
        $this->assertNotNull(FinanceTransaction::first()->journal_entry_id);
        $this->assertEquals(-250000, $this->balance('1101'));

        $big = $service->submit($this->payload(['amount' => 500000]), $staff);
        $service->approveByManager($big, $manager);

        $this->assertSame('pending_direksi', $big->fresh()->status);
        $this->assertSame(1, FinanceTransaction::count());
    }

    public function test_the_limit_never_lets_someone_approve_their_own_request(): void
    {
        FinanceSetting::set(FinanceSetting::EXPENSE_MANAGER_LIMIT, 300000);
        $manager = $this->user('Manajer', 'store_manager');
        $request = app(FinanceTransactionApprovalService::class)->submit($this->payload(['amount' => 100000]), $manager);

        $this->assertSame('pending_direksi', $request->status, 'Pengajuan manajer langsung ke direksi.');
        $this->expectException(RuntimeException::class);
        app(FinanceTransactionApprovalService::class)->approveByManager($request, $manager);
    }

    public function test_settings_page_saves_and_clears_the_limit(): void
    {
        $admin = $this->user('Admin', 'super_admin');
        $this->actingAs($admin, 'web');

        Livewire::test(FinanceSettingsPage::class)->assertSuccessful()
            ->set('data.expense_manager_limit', 750000)->call('save');
        $this->assertSame(750000.0, FinanceSetting::expenseManagerLimit());

        Livewire::test(FinanceSettingsPage::class)->set('data.expense_manager_limit', null)->call('save');
        $this->assertNull(FinanceSetting::expenseManagerLimit());
    }

    // ------------------------------------------------------------- kas kecil

    public function test_petty_cash_balance_replenishment_and_direct_spending_rules(): void
    {
        $petty = $this->pettyAccount();
        $service = app(PettyCashService::class);
        $this->fund($petty, 600000);

        $this->assertSame(600000.0, $service->balance($petty));
        $this->assertSame(400000.0, $service->replenishAmount($petty));

        $staff = $this->user('Staf');
        $other = $this->user('Staf B', null, $this->otherStore);
        $base = ['type' => 'out', 'payment_account_id' => $petty->id, 'amount' => 150000];

        $this->assertTrue($service->allowsDirectSpending($base, $staff), 'Staf toko yang sama, di bawah batas.');
        $this->assertFalse($service->allowsDirectSpending($base, $other), 'Toko lain.');
        $this->assertFalse($service->allowsDirectSpending(array_merge($base, ['amount' => 250000]), $staff), 'Di atas batas per bukti.');
        $this->assertFalse($service->allowsDirectSpending(['payment_account_id' => null] + $base, $staff), 'Bukan kas kecil.');

        $petty->update(['custodian_id' => $other->id]);
        $this->assertTrue($service->allowsDirectSpending($base, $other->fresh()), 'Pemegang kas boleh meski beda toko.');

        $petty->update(['voucher_limit' => null]);
        $this->assertFalse($service->allowsDirectSpending($base, $staff), 'Tanpa batas: semua lewat persetujuan.');
    }

    public function test_petty_cash_cannot_be_overspent_and_replenish_moves_money_to_the_float(): void
    {
        $petty = $this->pettyAccount();
        $service = app(PettyCashService::class);
        $this->fund($petty, 100000);

        try {
            $service->assertSufficient($petty, 150000);
            $this->fail('Seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('tidak cukup', $e->getMessage());
        }

        $transfer = $service->replenish($petty, $this->acct('1101')->id, null, null);

        $this->assertEquals(900000, (float) $transfer->amount, 'Plafon 1.000.000 dikurangi saldo 100.000.');
        $this->assertSame(1000000.0, $service->balance($petty));

        $this->expectException(RuntimeException::class);
        $service->replenish($petty, $this->acct('1101')->id, null, null); // sudah penuh
    }

    public function test_a_clerk_spends_petty_cash_without_waiting_for_approval_but_not_beyond_the_balance(): void
    {
        $petty = $this->pettyAccount();
        $this->fund($petty, 300000);
        $clerk = $this->user('Kasir', 'kasir');
        $this->actingAs($clerk, 'web');

        Livewire::test(CreateFinanceTransaction::class)
            ->fillForm(['type' => 'out', 'finance_category_id' => $this->expense->id, 'payment_account_id' => $petty->id, 'store_id' => $this->store->id, 'amount' => 120000, 'transaction_date' => now()->toDateString(), 'description' => 'Parkir dan bensin'])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame(1, FinanceTransaction::count(), 'Langsung tercatat.');
        $this->assertSame(0, \App\Models\FinanceTransactionApprovalRequest::count());
        $this->assertEquals(180000, $this->balance('1103'));

        // Di atas saldo: ditolak, tidak ada pengajuan.
        Livewire::test(CreateFinanceTransaction::class)
            ->fillForm(['type' => 'out', 'finance_category_id' => $this->expense->id, 'payment_account_id' => $petty->id, 'store_id' => $this->store->id, 'amount' => 190000, 'transaction_date' => now()->toDateString(), 'description' => 'Terlalu besar'])
            ->call('create');

        $this->assertSame(1, FinanceTransaction::count());
        $this->assertSame(0, \App\Models\FinanceTransactionApprovalRequest::count());
    }

    // ------------------------------------------------------------- hitung kas harian

    public function test_cash_count_records_the_difference_and_requires_an_explanation(): void
    {
        $petty = $this->pettyAccount();
        $this->fund($petty, 500000);
        $service = app(CashCountService::class);

        try {
            $service->record($petty->fresh(), now(), 480000, null, null);
            $this->fail('Selisih tanpa catatan harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('selisih', strtolower($e->getMessage()));
        }

        $count = $service->record($petty->fresh(), now(), 480000, 'Ongkos parkir belum dicatat', null);

        $this->assertEquals(500000, (float) $count->system_balance);
        $this->assertEquals(-20000, (float) $count->difference);
        $this->assertTrue($count->hasDifference());

        $this->expectException(RuntimeException::class);
        $service->record($petty->fresh(), now(), 500000, null, null); // sudah dihitung hari ini
    }

    public function test_cash_count_validation_for_bank_accounts_future_dates_and_matching_counts(): void
    {
        $bank = PaymentAccount::create(['name' => 'BCA', 'kind' => 'bank', 'chart_of_account_id' => $this->cashCoa('1104', 'BCA')->id, 'is_active' => true]);
        $petty = $this->pettyAccount();
        $service = app(CashCountService::class);

        foreach ([
            fn () => $service->record($bank, now(), 0, null, null),
            fn () => $service->record($petty, now()->addDay(), 0, null, null),
            fn () => $service->record($petty, now(), -1, null, null),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Seharusnya ditolak.');
            } catch (RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        $this->assertFalse($service->record($petty, now(), 0, null, null)->hasDifference(), 'Saldo 0 dan dihitung 0: cocok tanpa catatan.');
    }

    public function test_adjusting_a_shortage_and_a_surplus_posts_to_the_right_accounts_once(): void
    {
        $petty = $this->pettyAccount();
        $this->fund($petty, 500000);
        $service = app(CashCountService::class);

        $short = $service->record($petty->fresh(), now(), 470000, 'Hilang', null);
        $service->adjust($short, null);

        $this->assertEquals(470000, $this->balance('1103'));
        $this->assertEquals(30000, $this->balance('7900'), 'Kekurangan ke beban non-operasional.');
        $this->assertTrue($short->fresh()->isAdjusted());

        try {
            $service->adjust($short->fresh(), null);
            $this->fail('Tidak boleh dijurnalkan dua kali.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah dijurnalkan', $e->getMessage());
        }

        Carbon::setTestNow('2026-10-08 09:00:00');
        $over = $service->record($petty->fresh(), now(), 480000, 'Ketemu uang kembalian', null);
        $service->adjust($over, null);

        $this->assertEquals(480000, $this->balance('1103'));
        $this->assertEquals(10000, $this->balance('7200'), 'Kelebihan ke pendapatan non-operasional.');
    }

    public function test_cash_count_list_renders_for_admin(): void
    {
        $this->actingAs($this->user('Admin', 'super_admin'), 'web');
        $petty = $this->pettyAccount();
        $count = app(CashCountService::class)->record($petty, now(), 0, null, null);

        Livewire::test(ListCashCounts::class)->assertSuccessful()->assertCanSeeTableRecords([$count]);
    }

    // ------------------------------------------------------------- anggaran

    private function spend(float $amount, string $date, ?Store $store = null, ?string $categoryAccount = null): void
    {
        $category = $categoryAccount
            ? FinanceCategory::create(['name' => 'Kat ' . $categoryAccount, 'type' => 'out', 'chart_of_account_id' => $this->acct($categoryAccount)->id, 'is_active' => true])
            : $this->expense;

        $transaction = FinanceTransaction::create(['type' => 'out', 'finance_category_id' => $category->id, 'store_id' => ($store ?? $this->store)->id, 'amount' => $amount, 'transaction_date' => $date, 'description' => 'x']);
        $entry = app(FinanceTransactionPostingService::class)->post($transaction->load('category'), null);
        $transaction->update(['journal_entry_id' => $entry->id]);
    }

    public function test_budget_compare_statuses_and_unbudgeted_spending(): void
    {
        $month = Carbon::parse('2026-10-01');
        Budget::create(['period_month' => '2026-10-15', 'chart_of_account_id' => $this->acct('6410')->id, 'amount' => 1000000]); // ATK
        Budget::create(['period_month' => '2026-10-01', 'chart_of_account_id' => $this->acct('6220')->id, 'amount' => 500000]);   // listrik
        Budget::create(['period_month' => '2026-10-01', 'chart_of_account_id' => $this->acct('6230')->id, 'amount' => 400000]);   // internet

        $this->spend(850000, '2026-10-03');                       // ATK 85% -> hampir
        $this->spend(600000, '2026-10-04', null, '6220');         // listrik 120% -> lewat
        $this->spend(100000, '2026-10-05', null, '6230');         // internet 25% -> aman
        $this->spend(75000, '2026-10-06', null, '6440');          // tanpa anggaran
        $this->spend(900000, '2026-09-28');                        // bulan lain: tidak dihitung

        $rows = app(BudgetService::class)->compare($month)->keyBy(fn ($r) => $r['account']->code);

        $this->assertSame('hampir', $rows['6410']['status']);
        $this->assertSame(85.0, $rows['6410']['percent']);
        $this->assertSame('lewat', $rows['6220']['status']);
        $this->assertSame(-100000.0, $rows['6220']['remaining']);
        $this->assertSame('aman', $rows['6230']['status']);
        $this->assertSame('tanpa_anggaran', $rows['6440']['status']);
        $this->assertSame(850000.0, $rows['6410']['actual'], 'September tidak ikut.');
    }

    public function test_budget_is_per_scope_unique_and_copied_from_the_previous_month_without_duplicates(): void
    {
        Budget::create(['period_month' => '2026-09-10', 'chart_of_account_id' => $this->acct('6410')->id, 'amount' => 1000000]);
        Budget::create(['period_month' => '2026-09-10', 'store_id' => $this->store->id, 'chart_of_account_id' => $this->acct('6410')->id, 'amount' => 300000]);

        $this->assertSame('2026-09-01', Budget::first()->period_month->toDateString(), 'Disimpan sebagai tanggal 1.');

        $service = app(BudgetService::class);
        $this->assertSame(1, $service->copyFromPrevious(Carbon::parse('2026-10-20'), null, null), 'Hanya anggaran perusahaan.');
        $this->assertSame(0, $service->copyFromPrevious(Carbon::parse('2026-10-20'), null, null), 'Tidak menggandakan.');
        $this->assertSame(1, $service->copyFromPrevious(Carbon::parse('2026-10-01'), $this->store->id, null));
        $this->assertSame(4, Budget::count());

        $this->expectException(\Illuminate\Database\QueryException::class);
        Budget::create(['period_month' => '2026-10-02', 'chart_of_account_id' => $this->acct('6410')->id, 'amount' => 1]); // duplikat lingkup
    }

    public function test_budget_report_page_renders(): void
    {
        $this->actingAs($this->user('Admin', 'super_admin'), 'web');
        Budget::create(['period_month' => '2026-10-01', 'chart_of_account_id' => $this->acct('6410')->id, 'amount' => 1000000]);
        $this->spend(900000, '2026-10-03');

        Livewire::test(BudgetVsActualReport::class)->assertSuccessful()->assertSee('Beban ATK & Perlengkapan Kantor')->assertSee('Hampir habis');
    }
}
