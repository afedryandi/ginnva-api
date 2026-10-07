<?php

namespace Tests\Feature;

use App\Filament\Resources\FinanceTransactionResource;
use App\Filament\Resources\FinanceTransactionResource\Pages\CreateFinanceTransaction;
use App\Filament\Resources\FinanceTransactionResource\Pages\EditFinanceTransaction;
use App\Filament\Resources\FinanceTransactionResource\Pages\ListFinanceTransactions;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\FinanceTransactionApprovalRequest;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Models\User;
use App\Services\FinanceTransactionPostingService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Transaksi Keuangan: pencatatan pemasukan/pengeluaran yang otomatis
 * diposting ke Jurnal Umum (debit/kredit benar), pengeluaran staf lewat
 * persetujuan, validasi & kategori, pencegah kirim ganda, periode yang
 * sudah ditutup, ubah (jurnal dibalik lalu diposting ulang dengan alasan,
 * hanya full-access yang boleh mengubah nilai) dan hapus (jurnal dibalik),
 * filter daftar, isolasi per toko, dan izin per aksi.
 */
class FinanceTransactionTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private FinanceCategory $expense;
    private FinanceCategory $income;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        foreach (['kasir', 'store_manager'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->expense = FinanceCategory::create(['name' => 'Beban Listrik', 'type' => 'out', 'chart_of_account_id' => ChartOfAccount::where('code', '6510')->value('id'), 'is_active' => true]);
        $this->income = FinanceCategory::create(['name' => 'Pendapatan Lain', 'type' => 'in', 'chart_of_account_id' => ChartOfAccount::where('code', '4400')->value('id'), 'is_active' => true]);
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id,
        ], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function admin(): User
    {
        return $this->user('super_admin', null, ['store_id' => null]);
    }

    /** Transaksi yang sudah terposting (seperti hasil pencatatan normal). */
    private function transaction(array $overrides = []): FinanceTransaction
    {
        $transaction = FinanceTransaction::create(array_merge([
            'type' => 'out', 'finance_category_id' => $this->expense->id, 'store_id' => $this->store->id, 'amount' => 500000,
            'transaction_date' => now()->toDateString(), 'description' => 'Tagihan listrik',
        ], $overrides));
        $entry = app(FinanceTransactionPostingService::class)->post($transaction->load('category'), null);
        $transaction->update(['journal_entry_id' => $entry->id]);

        return $transaction->fresh();
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'type' => 'out', 'finance_category_id' => $this->expense->id, 'store_id' => $this->store->id, 'amount' => 250000,
            'transaction_date' => now()->toDateString(), 'description' => 'Beli token listrik',
        ], $overrides);
    }

    private function lines(FinanceTransaction $transaction)
    {
        return JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($transaction->journal_entry_id)->lines;
    }

    private function accountId(string $code): int
    {
        return ChartOfAccount::where('code', $code)->value('id');
    }

    // ------------------------------------------------------------- catat & posting

    public function test_admin_records_an_expense_which_is_posted_debit_expense_credit_cash(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'web');

        Livewire::test(CreateFinanceTransaction::class)->fillForm($this->form())->call('create')->assertHasNoFormErrors();

        $transaction = FinanceTransaction::firstOrFail();
        $this->assertMatchesRegularExpression('/^TRX-\d{6}-[A-Z0-9]{4}$/', $transaction->transaction_number);
        $this->assertSame($admin->id, $transaction->created_by);
        $this->assertSame('out', $transaction->type);
        $this->assertNotNull($transaction->journal_entry_id);

        $lines = $this->lines($transaction);
        $this->assertEquals(250000, $lines->firstWhere('chart_of_account_id', $this->accountId('6510'))->debit);
        $this->assertEquals(250000, $lines->firstWhere('chart_of_account_id', $this->accountId('1101'))->credit);
    }

    public function test_an_income_is_posted_debit_cash_credit_revenue(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::test(CreateFinanceTransaction::class)
            ->fillForm($this->form(['type' => 'in', 'finance_category_id' => $this->income->id, 'amount' => 1000000]))
            ->call('create')->assertHasNoFormErrors();

        $lines = $this->lines(FinanceTransaction::firstOrFail());
        $this->assertEquals(1000000, $lines->firstWhere('chart_of_account_id', $this->accountId('1101'))->debit);
        $this->assertEquals(1000000, $lines->firstWhere('chart_of_account_id', $this->accountId('4400'))->credit);
    }

    public function test_the_type_always_follows_the_chosen_category(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::test(CreateFinanceTransaction::class)
            ->fillForm($this->form(['type' => 'in', 'finance_category_id' => $this->expense->id]))
            ->call('create');

        $this->assertSame('out', FinanceTransaction::firstOrFail()->type);
    }

    public function test_staff_expenses_go_to_approval_while_staff_income_is_recorded_directly(): void
    {
        $staff = $this->user('kasir');
        $this->actingAs($staff, 'web');

        Livewire::test(CreateFinanceTransaction::class)->fillForm($this->form(['store_id' => $this->otherStore->id]))->call('create');

        $this->assertSame(0, FinanceTransaction::count(), 'Pengeluaran belum tercatat sebelum disetujui.');
        $request = FinanceTransactionApprovalRequest::firstOrFail();
        $this->assertSame('pending_manager', $request->status);
        $this->assertSame($staff->id, $request->requested_by);
        $this->assertSame($this->store->id, (int) $request->payload['store_id'], 'Toko dipaksa ke toko pemohon.');

        Livewire::test(CreateFinanceTransaction::class)
            ->fillForm($this->form(['type' => 'in', 'finance_category_id' => $this->income->id, 'description' => 'Pemasukan staf']))
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame(1, FinanceTransaction::count());
        $this->assertSame('in', FinanceTransaction::firstOrFail()->type);
    }

    public function test_a_store_manager_expense_skips_the_manager_stage(): void
    {
        $this->actingAs($this->user('store_manager'), 'web');

        Livewire::test(CreateFinanceTransaction::class)->fillForm($this->form())->call('create');

        $this->assertSame('pending_direksi', FinanceTransactionApprovalRequest::firstOrFail()->status);
        $this->assertSame(0, FinanceTransaction::count());
    }

    // ------------------------------------------------------------- validasi

    public function test_amount_date_store_and_category_are_validated(): void
    {
        $this->actingAs($this->admin(), 'web');
        $create = fn (array $over) => Livewire::test(CreateFinanceTransaction::class)->fillForm($this->form($over))->call('create');

        $create(['amount' => null])->assertHasFormErrors(['amount' => 'required']);
        $create(['amount' => 0])->assertHasFormErrors(['amount']);
        $create(['amount' => -5000])->assertHasFormErrors(['amount']);
        $create(['amount' => 'abc'])->assertHasFormErrors(['amount']);
        $create(['transaction_date' => now()->addDays(3)->toDateString()])->assertHasFormErrors(['transaction_date']);
        $create(['transaction_date' => now()->subYears(5)->toDateString()])->assertHasFormErrors(['transaction_date']);
        $create(['store_id' => null])->assertHasFormErrors(['store_id' => 'required']);
        $create(['finance_category_id' => null])->assertHasFormErrors(['finance_category_id' => 'required']);

        $this->assertSame(0, FinanceTransaction::count());
    }

    public function test_inactive_and_group_categories_are_rejected_by_the_server(): void
    {
        $this->actingAs($this->admin(), 'web');
        $inactive = FinanceCategory::create(['name' => 'Mati', 'type' => 'out', 'chart_of_account_id' => $this->accountId('6510'), 'is_active' => false]);
        $group = FinanceCategory::create(['name' => 'Grup Beban', 'type' => 'out', 'is_group' => true, 'is_active' => true]);

        foreach ([$inactive, $group] as $bad) {
            Livewire::test(CreateFinanceTransaction::class)->fillForm($this->form(['finance_category_id' => $bad->id]))->call('create');
        }

        $this->assertSame(0, FinanceTransaction::count());
    }

    public function test_a_category_without_a_linked_account_cannot_be_posted_and_leaves_nothing_behind(): void
    {
        $this->actingAs($this->admin(), 'web');
        // Model menolak membuat kategori baru tanpa akun, jadi putuskan tautannya lewat update massal
        // (meniru data lama/terputus) agar jalur posting yang menolaknya yang teruji.
        $unlinked = FinanceCategory::create(['name' => 'Belum Dihubungkan', 'type' => 'out', 'chart_of_account_id' => $this->accountId('6510'), 'is_active' => true]);
        FinanceCategory::whereKey($unlinked->id)->update(['chart_of_account_id' => null]);

        Livewire::test(CreateFinanceTransaction::class)->fillForm($this->form(['finance_category_id' => $unlinked->id]))->call('create');

        $this->assertSame(0, FinanceTransaction::count(), 'Tidak ada transaksi tanpa jurnal.');
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('reference_type', 'finance_transaction')->count());
    }

    public function test_an_identical_submission_within_ten_minutes_is_blocked(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::test(CreateFinanceTransaction::class)->fillForm($this->form())->call('create');
        Livewire::test(CreateFinanceTransaction::class)->fillForm($this->form())->call('create');
        $this->assertSame(1, FinanceTransaction::count());

        Livewire::test(CreateFinanceTransaction::class)->fillForm($this->form(['description' => 'Beli token listrik (nota 2)']))->call('create');
        $this->assertSame(2, FinanceTransaction::count(), 'Keterangan berbeda = transaksi terpisah.');
    }

    public function test_a_closed_period_blocks_new_entries_dated_in_it(): void
    {
        $admin = $this->admin();
        AccountingPeriod::create(['period_month' => '2026-01-01', 'closed_by' => $admin->id, 'closed_at' => now()]);
        $this->actingAs($admin, 'web');

        Livewire::test(CreateFinanceTransaction::class)->fillForm($this->form(['transaction_date' => '2026-01-15']))->call('create');

        $this->assertSame(0, FinanceTransaction::count());
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('reference_type', 'finance_transaction')->count());
    }

    // ------------------------------------------------------------- ubah

    public function test_full_access_edit_reverses_the_old_journal_posts_a_new_one_and_requires_a_reason(): void
    {
        $admin = $this->admin();
        $transaction = $this->transaction();
        $oldEntryId = $transaction->journal_entry_id;
        $this->actingAs($admin, 'web');

        Livewire::test(EditFinanceTransaction::class, ['record' => $transaction->getKey()])
            ->fillForm(['amount' => 750000, 'change_reason' => ''])
            ->call('save')->assertHasFormErrors(['change_reason' => 'required']);
        $this->assertEquals(500000, $transaction->fresh()->amount);

        Livewire::test(EditFinanceTransaction::class, ['record' => $transaction->getKey()])
            ->fillForm(['amount' => 750000, 'change_reason' => 'Salah ketik nominal'])
            ->call('save')->assertHasNoFormErrors();

        $fresh = $transaction->fresh();
        $this->assertEquals(750000, $fresh->amount);
        $this->assertNotSame($oldEntryId, $fresh->journal_entry_id, 'Jurnal baru menggantikan yang lama.');
        $this->assertTrue(JournalEntry::withoutGlobalScopes()->where('reference_type', 'reversal')->where('reference_id', $oldEntryId)->exists(), 'Jurnal lama dibalik, bukan diedit.');
        $this->assertEquals(750000, $this->lines($fresh)->firstWhere('chart_of_account_id', $this->accountId('6510'))->debit);
        $this->assertTrue(Activity::where('log_name', 'finance_transaction')->where('subject_id', $transaction->id)->where('description', 'like', '%Salah ketik nominal%')->exists());
    }

    public function test_changing_the_category_follows_the_new_categorys_account(): void
    {
        $this->actingAs($this->admin(), 'web');
        $transaction = $this->transaction();
        $other = FinanceCategory::create(['name' => 'Beban Lain', 'type' => 'out', 'chart_of_account_id' => $this->accountId('6110'), 'is_active' => true]);

        Livewire::test(EditFinanceTransaction::class, ['record' => $transaction->getKey()])
            ->fillForm(['finance_category_id' => $other->id, 'change_reason' => 'Salah kategori'])
            ->call('save');

        $this->assertNotNull($this->lines($transaction->fresh())->firstWhere('chart_of_account_id', $this->accountId('6110')));
    }

    public function test_non_full_access_can_only_change_the_description_and_receipt(): void
    {
        $transaction = $this->transaction();
        $this->actingAs($this->user('store_manager'), 'web');

        Livewire::test(EditFinanceTransaction::class, ['record' => $transaction->getKey()])
            ->fillForm(['description' => 'Keterangan diperjelas', 'amount' => 1, 'finance_category_id' => $this->income->id, 'transaction_date' => now()->subDay()->toDateString()])
            ->call('save');

        $fresh = $transaction->fresh();
        $this->assertSame('Keterangan diperjelas', $fresh->description);
        $this->assertEquals(500000, $fresh->amount, 'Nominal terkunci.');
        $this->assertSame($this->expense->id, $fresh->finance_category_id);
        $this->assertSame(now()->toDateString(), $fresh->transaction_date->toDateString());
        $this->assertEquals(500000, $this->lines($fresh)->firstWhere('chart_of_account_id', $this->accountId('6510'))->debit);
    }

    public function test_editing_is_blocked_for_another_stores_record_and_for_a_closed_period(): void
    {
        $admin = $this->admin();
        $theirs = $this->transaction(['store_id' => $this->otherStore->id]);
        $old = $this->transaction(['transaction_date' => '2026-01-15']);
        AccountingPeriod::create(['period_month' => '2026-01-01', 'closed_by' => $admin->id, 'closed_at' => now()]);

        $this->actingAs($this->user('store_manager'), 'web');
        $this->assertFalse((bool) FinanceTransactionResource::canEdit($theirs));

        $this->actingAs($admin, 'web');
        Livewire::test(EditFinanceTransaction::class, ['record' => $old->getKey()])
            ->fillForm(['amount' => 999, 'change_reason' => 'Coba ubah periode tertutup'])
            ->call('save');

        $this->assertEquals(500000, $old->fresh()->amount);
    }

    // ------------------------------------------------------------- hapus

    public function test_delete_needs_a_reason_and_reverses_the_journal(): void
    {
        $this->actingAs($this->admin(), 'web');
        $transaction = $this->transaction();
        $entryId = $transaction->journal_entry_id;

        Livewire::test(ListFinanceTransactions::class)
            ->callTableAction('delete', $transaction, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        $this->assertNotNull(FinanceTransaction::find($transaction->id));

        Livewire::test(ListFinanceTransactions::class)->callTableAction('delete', $transaction, data: ['reason' => 'Transaksi dobel']);

        $this->assertNull(FinanceTransaction::find($transaction->id));
        $this->assertTrue(JournalEntry::withoutGlobalScopes()->where('reference_type', 'reversal')->where('reference_id', $entryId)->exists());
        $this->assertTrue(Activity::where('log_name', 'finance_transaction')->where('description', 'like', '%Transaksi dobel%')->exists());
    }

    public function test_a_transaction_in_a_closed_period_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $transaction = $this->transaction(['transaction_date' => '2026-01-15']);
        AccountingPeriod::create(['period_month' => '2026-01-01', 'closed_by' => $admin->id, 'closed_at' => now()]);
        $this->actingAs($admin, 'web');

        Livewire::test(ListFinanceTransactions::class)->callTableAction('delete', $transaction, data: ['reason' => 'Coba hapus']);

        $this->assertNotNull(FinanceTransaction::find($transaction->id));
    }

    public function test_only_full_access_or_an_explicit_grant_can_delete(): void
    {
        $transaction = $this->transaction();

        $this->actingAs($this->user('store_manager'), 'web');
        $this->assertFalse((bool) FinanceTransactionResource::canDelete($transaction));

        $granted = $this->user('kasir', null, ['menu_permissions' => [FinanceTransactionResource::class => ['delete']]]);
        $this->actingAs($granted, 'web');
        $this->assertTrue((bool) FinanceTransactionResource::canDelete($transaction));

        $this->actingAs($this->admin(), 'web');
        $this->assertTrue((bool) FinanceTransactionResource::canDelete($transaction));
    }

    // ------------------------------------------------------------- daftar

    public function test_list_is_store_scoped_and_filters_work(): void
    {
        $mine = $this->transaction();
        $income = $this->transaction(['type' => 'in', 'finance_category_id' => $this->income->id, 'description' => 'Pemasukan']);
        $theirs = $this->transaction(['store_id' => $this->otherStore->id]);
        $unposted = FinanceTransaction::create(['type' => 'out', 'finance_category_id' => $this->expense->id, 'store_id' => $this->store->id, 'amount' => 1000, 'transaction_date' => now()->toDateString()]);
        $old = $this->transaction(['transaction_date' => now()->subMonths(2)->toDateString(), 'description' => 'Lama']);

        $this->actingAs($this->user('store_manager'), 'web');
        Livewire::test(ListFinanceTransactions::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$mine, $income, $unposted, $old])->assertCanNotSeeTableRecords([$theirs]);

        Livewire::test(ListFinanceTransactions::class)->filterTable('type', 'in')
            ->assertCanSeeTableRecords([$income])->assertCanNotSeeTableRecords([$mine, $old]);

        Livewire::test(ListFinanceTransactions::class)->filterTable('belum_terposting')
            ->assertCanSeeTableRecords([$unposted])->assertCanNotSeeTableRecords([$mine]);

        Livewire::test(ListFinanceTransactions::class)->filterTable('finance_category_id', $this->income->id)
            ->assertCanSeeTableRecords([$income])->assertCanNotSeeTableRecords([$mine]);

        Livewire::test(ListFinanceTransactions::class)
            ->filterTable('transaction_date', ['from' => now()->subMonth()->toDateString(), 'until' => now()->toDateString()])
            ->assertCanSeeTableRecords([$mine, $income])->assertCanNotSeeTableRecords([$old]);

        $this->actingAs($this->admin(), 'web');
        Livewire::test(ListFinanceTransactions::class)->assertCanSeeTableRecords([$mine, $theirs]);
    }

    public function test_totals_for_a_month_split_income_and_expense(): void
    {
        $this->transaction(['amount' => 300000]);
        $this->transaction(['amount' => 200000, 'description' => 'Lain']);
        $this->transaction(['type' => 'in', 'finance_category_id' => $this->income->id, 'amount' => 1000000]);
        $this->transaction(['amount' => 99999, 'transaction_date' => now()->subMonths(2)->toDateString()]);

        $totals = FinanceTransaction::totalsForMonth(now());

        $this->assertSame(['in' => 1000000.0, 'out' => 500000.0, 'net' => 500000.0], $totals);
    }

    public function test_menu_access_gates_the_resource(): void
    {
        $this->actingAs($this->user('kasir'), 'web');
        $this->assertTrue(FinanceTransactionResource::canViewAny());
        $this->assertTrue(FinanceTransactionResource::canCreate());

        $this->actingAs($this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(FinanceTransactionResource::canViewAny());
        $this->assertFalse(FinanceTransactionResource::canCreate());
    }
}
