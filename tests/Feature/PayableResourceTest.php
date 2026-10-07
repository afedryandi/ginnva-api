<?php

namespace Tests\Feature;

use App\Filament\Resources\PayableResource;
use App\Filament\Resources\PayableResource\Pages\ListPayables;
use App\Filament\Resources\PayableResource\Pages\ViewPayable;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Payable;
use App\Models\PayablePayment;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PayableService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Hutang Usaha (layar admin): daftar & filter per toko, catat tagihan manual
 * (akun yang didebit harus Aset/Beban), catat pembayaran (izin eksplisit,
 * pembuat tidak boleh membayar tagihannya sendiri), batalkan pembayaran/
 * tagihan (full-access, jurnal dibalik), tagihan dari Permintaan Pembelian
 * tidak dibatalkan di sini, rekonsiliasi, dan badge jatuh tempo. Logika inti
 * service sudah diuji di PayableServiceTest.
 */
class PayableResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private ChartOfAccount $expense;
    private Supplier $supplier;

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
        $this->expense = ChartOfAccount::where('code', '6210')->firstOrFail();
        $this->supplier = Supplier::create(['name' => 'PT Properti Jaya']);
    }

    private function user(string $role, ?Store $store = null, array $extra = []): User
    {
        return tap(User::create(array_merge([
            'name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid() . '@test.local', 'password' => 'x', 'store_id' => ($store ?? $this->store)->id,
        ], $extra)), fn (User $u) => $u->assignRole($role));
    }

    private function admin(string $name = 'Admin'): User
    {
        return $this->user('super_admin', null, ['name' => $name, 'store_id' => null]);
    }

    private function payable(array $overrides = [], ?User $creator = null): Payable
    {
        return app(PayableService::class)->createWithJournal(array_merge([
            'supplier_name' => $this->supplier->name, 'supplier_id' => $this->supplier->id, 'store_id' => $this->store->id,
            'amount' => 500000, 'created_by' => $creator?->id, 'due_date' => now()->addDays(14)->toDateString(),
        ], $overrides), $this->expense->id);
    }

    /** Record dengan withCount seperti di tabel asli (aksi memakai active_payments_count). */
    private function row(Payable $payable): Payable
    {
        return Payable::withoutGlobalScopes()->withCount(['payments as active_payments_count' => fn ($q) => $q->whereNull('voided_at')])->findOrFail($payable->id);
    }

    private function granted(string $action, ?Store $store = null): User
    {
        return $this->user('kasir', $store, ['menu_permissions' => [PayableResource::class => [$action]]]);
    }

    // ------------------------------------------------------------- service: akun debit

    public function test_a_manual_payable_may_only_debit_an_active_asset_or_expense_account(): void
    {
        $service = app(PayableService::class);
        $payableAccount = ChartOfAccount::where('code', '2110')->firstOrFail();
        $revenue = ChartOfAccount::where('code', '4400')->firstOrFail();
        $inactive = ChartOfAccount::where('code', '6110')->firstOrFail();
        $inactive->update(['is_active' => false]);

        foreach ([$payableAccount->id, $revenue->id, $inactive->id, 999999] as $bad) {
            try {
                $service->createWithJournal(['supplier_name' => 'X', 'amount' => 1000], $bad);
                $this->fail("Akun {$bad} seharusnya ditolak.");
            } catch (RuntimeException $e) {
                $this->assertSame('Akun yang didebit harus akun Aset atau Beban yang aktif.', $e->getMessage());
            }
        }

        $this->assertSame(0, Payable::withoutGlobalScopes()->count());
        $asset = ChartOfAccount::where('code', '1101')->firstOrFail();
        $this->assertNotNull($service->createWithJournal(['supplier_name' => 'Peralatan', 'amount' => 1000], $asset->id), 'Akun aset (mis. beli peralatan) sah.');
    }

    // ------------------------------------------------------------- daftar

    public function test_list_is_store_scoped_and_filters_work(): void
    {
        $other = Supplier::create(['name' => 'CV Lain']);
        $mine = $this->payable();
        $paid = $this->payable(['supplier_name' => $other->name, 'supplier_id' => $other->id]);
        app(PayableService::class)->recordPayment($paid, 500000, now(), $this->admin('Pembayar')->id);
        $overdue = $this->payable(['due_date' => now()->subDays(5)->toDateString()]);
        $theirs = $this->payable(['store_id' => $this->otherStore->id]);

        $this->actingAs($this->user('store_manager'), 'web');
        Livewire::test(ListPayables::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$mine, $paid, $overdue])->assertCanNotSeeTableRecords([$theirs]);

        Livewire::test(ListPayables::class)->filterTable('status', 'paid')
            ->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$mine, $overdue]);
        Livewire::test(ListPayables::class)->filterTable('overdue')
            ->assertCanSeeTableRecords([$overdue])->assertCanNotSeeTableRecords([$mine, $paid]);
        Livewire::test(ListPayables::class)->filterTable('supplier_id', $other->id)
            ->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$mine]);
        Livewire::test(ListPayables::class)->filterTable('source_type', 'manual')
            ->assertCanSeeTableRecords([$mine, $paid, $overdue]);
        Livewire::test(ListPayables::class)
            ->filterTable('due_range', ['from' => now()->subDays(10)->toDateString(), 'until' => now()->subDay()->toDateString()])
            ->assertCanSeeTableRecords([$overdue])->assertCanNotSeeTableRecords([$mine]);

        $this->assertSame('1', PayableResource::getNavigationBadge(), 'Badge: hanya yang belum lunas & lewat jatuh tempo di toko sendiri.');

        $this->actingAs($this->admin(), 'web');
        Livewire::test(ListPayables::class)->assertCanSeeTableRecords([$mine, $theirs]);
    }

    // ------------------------------------------------------------- catat manual

    public function test_admin_records_a_manual_bill_with_a_balanced_journal(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::test(ListPayables::class)
            ->callTableAction('create_manual', data: [
                'supplier_id' => $this->supplier->id, 'invoice_number' => 'INV-2026-001', 'amount' => 750000, 'debit_account_id' => $this->expense->id,
                'entry_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'notes' => 'Sewa bulan ini',
            ])->assertHasNoTableActionErrors();

        $payable = Payable::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('unpaid', $payable->status);
        $this->assertSame('INV-2026-001', $payable->invoice_number);
        $this->assertSame('PT Properti Jaya', $payable->supplier_name);
        $lines = JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($payable->journal_entry_id)->lines;
        $this->assertEquals(750000, $lines->firstWhere('chart_of_account_id', $this->expense->id)->debit);
        $this->assertEquals(750000, $lines->firstWhere('chart_of_account_id', ChartOfAccount::where('code', '2110')->value('id'))->credit);
    }

    public function test_manual_form_validation_and_server_side_account_check(): void
    {
        $this->actingAs($this->admin(), 'web');
        $base = ['supplier_id' => $this->supplier->id, 'amount' => 1000, 'debit_account_id' => $this->expense->id, 'entry_date' => now()->toDateString()];

        Livewire::test(ListPayables::class)->callTableAction('create_manual', data: [...$base, 'supplier_id' => null])->assertHasTableActionErrors(['supplier_id' => 'required']);
        Livewire::test(ListPayables::class)->callTableAction('create_manual', data: [...$base, 'amount' => 0])->assertHasTableActionErrors(['amount']);
        Livewire::test(ListPayables::class)->callTableAction('create_manual', data: [...$base, 'entry_date' => now()->addDays(3)->toDateString()])->assertHasTableActionErrors(['entry_date']);

        Livewire::test(ListPayables::class)->callTableAction('create_manual', data: [...$base, 'debit_account_id' => ChartOfAccount::where('code', '4400')->value('id')]);
        $this->assertSame(0, Payable::withoutGlobalScopes()->count(), 'Akun Pendapatan tidak boleh didebit, walau nilainya dikirim langsung.');
    }

    public function test_the_manual_button_follows_explicit_create_permission(): void
    {
        $this->actingAs($this->user('kasir'), 'web');
        $this->assertFalse((bool) PayableResource::canCreate());

        $this->actingAs($this->granted('create'), 'web');
        $this->assertTrue((bool) PayableResource::canCreate());

        $this->actingAs($this->admin(), 'web');
        $this->assertTrue((bool) PayableResource::canCreate());
    }

    // ------------------------------------------------------------- pembayaran

    public function test_paying_needs_explicit_permission_and_updates_the_bill(): void
    {
        $payable = $this->payable();

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertFalse((bool) PayableResource::canPay());
        Livewire::test(ListPayables::class)->assertTableActionHidden('pay', $this->row($payable));

        $payer = $this->granted('update');
        $this->actingAs($payer, 'web');
        Livewire::test(ListPayables::class)
            ->assertTableActionVisible('pay', $this->row($payable))
            ->callTableAction('pay', $this->row($payable), data: [
                'amount' => 200000, 'payment_account_id' => ChartOfAccount::where('code', '1101')->value('id'), 'payment_date' => now()->toDateString(),
            ])->assertHasNoTableActionErrors();

        $fresh = $payable->fresh();
        $this->assertSame('partial', $fresh->status);
        $this->assertEquals(200000, $fresh->amount_paid);
        $this->assertSame($payer->id, PayablePayment::firstOrFail()->created_by);
    }

    public function test_the_creator_cannot_pay_their_own_bill_and_overpayment_is_refused(): void
    {
        $creator = $this->granted('update');
        $payable = $this->payable([], $creator);
        $other = $this->granted('update');
        $cash = ChartOfAccount::where('code', '1101')->value('id');

        $this->actingAs($creator, 'web');
        Livewire::test(ListPayables::class)->callTableAction('pay', $this->row($payable), data: ['amount' => 1000, 'payment_account_id' => $cash, 'payment_date' => now()->toDateString()]);
        $this->assertSame(0, PayablePayment::count(), 'Pemisahan tugas: pembuat tagihan tidak boleh membayarnya sendiri.');

        $this->actingAs($other, 'web');
        Livewire::test(ListPayables::class)->callTableAction('pay', $this->row($payable), data: ['amount' => 600000, 'payment_account_id' => $cash, 'payment_date' => now()->toDateString()]);
        $this->assertSame(0, PayablePayment::count(), 'Melebihi sisa tagihan ditolak.');

        Livewire::test(ListPayables::class)->callTableAction('pay', $this->row($payable), data: ['amount' => 500000, 'payment_account_id' => $cash, 'payment_date' => now()->toDateString()]);
        $this->assertSame('paid', $payable->fresh()->status);
    }

    public function test_only_open_bills_offer_payment_and_a_non_cash_source_account_is_refused(): void
    {
        $this->actingAs($this->admin('Pembayar'), 'web');
        $open = $this->payable();
        $paid = $this->payable(['supplier_name' => 'Lunas']);
        app(PayableService::class)->recordPayment($paid, 500000, now(), auth()->id());

        Livewire::test(ListPayables::class)
            ->assertTableActionVisible('pay', $this->row($open))
            ->assertTableActionHidden('pay', $this->row($paid->fresh()))
            ->callTableAction('pay', $this->row($open), data: ['amount' => 1000, 'payment_account_id' => ChartOfAccount::where('code', '6110')->value('id'), 'payment_date' => now()->toDateString()]);

        $this->assertSame(0, PayablePayment::where('payable_id', $open->id)->count(), 'Sumber pembayaran harus akun Kas/Bank aktif.');
    }

    // ------------------------------------------------------------- koreksi

    public function test_void_payment_is_full_access_only_and_reverses_the_journal(): void
    {
        $payable = $this->payable();
        $payment = app(PayableService::class)->recordPayment($payable, 300000, now(), $this->admin('Pembayar')->id);

        $this->actingAs($this->granted('update'), 'web');
        Livewire::test(ListPayables::class)->assertTableActionHidden('void_payment', $this->row($payable));

        $this->actingAs($this->admin('Pembatal'), 'web');
        Livewire::test(ListPayables::class)
            ->assertTableActionVisible('void_payment', $this->row($payable))
            ->callTableAction('void_payment', $this->row($payable), data: ['payment_id' => $payment->id, 'reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        Livewire::test(ListPayables::class)
            ->callTableAction('void_payment', $this->row($payable), data: ['payment_id' => $payment->id, 'reason' => 'Salah input']);

        $this->assertSame('unpaid', $payable->fresh()->status);
        $this->assertEquals(0, $payable->fresh()->amount_paid);
        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertTrue(JournalEntry::withoutGlobalScopes()->where('reference_type', 'reversal')->where('reference_id', $payment->journal_entry_id)->exists());
    }

    public function test_cancelling_a_bill_reverses_it_but_only_without_active_payments(): void
    {
        $this->actingAs($this->admin(), 'web');
        $clean = $this->payable(['supplier_name' => 'Bersih']);
        $withPayment = $this->payable(['supplier_name' => 'Ada Pembayaran']);
        app(PayableService::class)->recordPayment($withPayment, 1000, now(), $this->admin('Pembayar')->id);

        Livewire::test(ListPayables::class)
            ->assertTableActionVisible('cancel_payable', $this->row($clean))
            ->assertTableActionHidden('cancel_payable', $this->row($withPayment))
            ->callTableAction('cancel_payable', $this->row($clean), data: ['reason' => 'Salah catat']);

        $fresh = $clean->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('Salah catat', $fresh->cancel_reason);
        $this->assertEquals(0, $fresh->remainingAmount());
        $this->assertTrue(JournalEntry::withoutGlobalScopes()->where('reference_type', 'reversal')->where('reference_id', $clean->journal_entry_id)->exists());

        Livewire::test(ListPayables::class)->assertTableActionHidden('cancel_payable', $this->row($clean->fresh()));
    }

    public function test_a_bill_from_a_purchase_request_cannot_be_cancelled_from_the_menu(): void
    {
        $this->actingAs($this->admin(), 'web');
        $fromRequest = $this->payable(['supplier_name' => 'Dari Pembelian']);
        Payable::withoutGlobalScopes()->whereKey($fromRequest->id)->update(['source_type' => 'purchase_request', 'source_id' => 999]);

        Livewire::test(ListPayables::class)->assertTableActionHidden('cancel_payable', $this->row($fromRequest->fresh()));
    }

    // ------------------------------------------------------------- rekonsiliasi & tampilan

    public function test_reconciliation_matches_the_ledger_with_the_subledger(): void
    {
        $this->payable(['amount' => 500000]);
        $second = $this->payable(['amount' => 250000, 'supplier_name' => 'Kedua']);
        app(PayableService::class)->recordPayment($second, 100000, now(), $this->admin('Pembayar')->id);

        $result = app(PayableService::class)->reconcile();

        $this->assertEquals(650000, $result['gl']);
        $this->assertEquals(650000, $result['subledger']);
        $this->assertEqualsWithDelta(0, $result['diff'], 0.005);

        $this->actingAs($this->admin(), 'web');
        Livewire::test(ListPayables::class)->callTableAction('reconcile')->assertHasNoTableActionErrors();
    }

    public function test_the_detail_page_renders(): void
    {
        $payable = $this->payable();
        $this->actingAs($this->admin(), 'web');

        Livewire::test(ViewPayable::class, ['record' => $payable->getKey()])->assertSuccessful()->assertSee($payable->payable_number);
    }

    public function test_bills_cannot_be_edited_or_deleted_and_access_follows_menu_access(): void
    {
        $payable = $this->payable();

        $this->actingAs($this->admin(), 'web');
        $this->assertFalse(PayableResource::canEdit($payable));
        $this->assertFalse(PayableResource::canDelete($payable));
        $this->assertTrue(PayableResource::canViewAny());
        $this->assertTrue(PayableResource::canCorrect());

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertTrue(PayableResource::canViewAny());
        $this->assertFalse(PayableResource::canCorrect());

        $this->actingAs($this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(PayableResource::canViewAny());
    }
}
