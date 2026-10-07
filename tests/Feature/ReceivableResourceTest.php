<?php

namespace Tests\Feature;

use App\Filament\Resources\ReceivableResource;
use App\Filament\Resources\ReceivableResource\Pages\ListReceivables;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Store;
use App\Models\User;
use App\Services\ReceivableService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Piutang Usaha (layar admin): daftar & filter per toko, catat piutang
 * manual (hanya akun Pendapatan yang boleh dikredit), catat pelunasan
 * (izin eksplisit, pembuat tidak boleh menerima pelunasannya sendiri),
 * batalkan pelunasan/piutang (full-access, jurnal dibalik), piutang dari
 * Booking tidak dibatalkan di sini, rekonsiliasi, dan badge jatuh tempo.
 * Logika inti service sudah diuji di ReceivableServiceTest.
 */
class ReceivableResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Store $otherStore;
    private ChartOfAccount $revenue;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('kasir', 'web');
        Role::findOrCreate('store_manager', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->otherStore = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
        $this->revenue = ChartOfAccount::where('code', '7200')->firstOrFail();
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

    private function receivable(array $overrides = [], ?User $creator = null): Receivable
    {
        $data = array_merge([
            'customer_name' => 'Pak Andi', 'store_id' => $this->store->id, 'amount' => 500000, 'created_by' => $creator?->id,
            'due_date' => now()->addDays(14)->toDateString(),
        ], $overrides);

        return app(ReceivableService::class)->createWithJournal($data, $this->revenue->id);
    }

    /** Record dengan withCount seperti di tabel asli (aksi memakai active_payments_count). */
    private function row(Receivable $receivable): Receivable
    {
        return Receivable::withoutGlobalScopes()->withCount(['payments as active_payments_count' => fn ($q) => $q->whereNull('voided_at')])->findOrFail($receivable->id);
    }

    private function granted(string $action, ?Store $store = null): User
    {
        return $this->user('kasir', $store, ['menu_permissions' => [ReceivableResource::class => [$action]]]);
    }

    // ------------------------------------------------------------- service: akun kredit

    public function test_a_manual_receivable_may_only_credit_an_active_revenue_account(): void
    {
        $service = app(ReceivableService::class);
        $cash = ChartOfAccount::where('code', '1101')->firstOrFail();
        $payable = ChartOfAccount::where('code', '2110')->firstOrFail();
        $inactive = ChartOfAccount::where('code', '7100')->firstOrFail();
        $inactive->update(['is_active' => false]);

        foreach ([$cash->id, $payable->id, $inactive->id, 999999] as $bad) {
            try {
                $service->createWithJournal(['customer_name' => 'X', 'amount' => 1000], $bad);
                $this->fail("Akun {$bad} seharusnya ditolak.");
            } catch (RuntimeException $e) {
                $this->assertSame('Akun yang dikredit harus akun Pendapatan yang aktif.', $e->getMessage());
            }
        }

        $this->assertSame(0, Receivable::withoutGlobalScopes()->count());
        $this->assertNotNull($service->createWithJournal(['customer_name' => 'OK', 'amount' => 1000], ChartOfAccount::where('code', '4400')->value('id')), 'Pendapatan usaha biasa juga sah.');
    }

    // ------------------------------------------------------------- daftar

    public function test_list_is_store_scoped_and_filters_work(): void
    {
        $budi = Customer::create(['name' => 'Budi', 'phone_number' => '081200000009']);
        $mine = $this->receivable(['customer_name' => 'Budi', 'customer_id' => $budi->id]);
        $paid = $this->receivable(['customer_name' => 'Lunas']);
        app(ReceivableService::class)->recordPayment($paid, 500000, now(), $this->admin('Penerima')->id);
        $overdue = $this->receivable(['customer_name' => 'Terlambat', 'due_date' => now()->subDays(5)->toDateString()]);
        $theirs = $this->receivable(['customer_name' => 'Toko Lain', 'store_id' => $this->otherStore->id]);

        $manager = $this->user('store_manager');
        $this->actingAs($manager, 'web');
        Livewire::test(ListReceivables::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$mine, $paid, $overdue])->assertCanNotSeeTableRecords([$theirs]);

        Livewire::test(ListReceivables::class)->filterTable('status', 'paid')
            ->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$mine, $overdue]);
        Livewire::test(ListReceivables::class)->filterTable('overdue')
            ->assertCanSeeTableRecords([$overdue])->assertCanNotSeeTableRecords([$mine, $paid]);
        Livewire::test(ListReceivables::class)->filterTable('customer_id', $budi->id)
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$overdue]);
        Livewire::test(ListReceivables::class)->filterTable('source_type', 'manual')
            ->assertCanSeeTableRecords([$mine, $paid, $overdue]);
        Livewire::test(ListReceivables::class)
            ->filterTable('due_range', ['from' => now()->subDays(10)->toDateString(), 'until' => now()->subDay()->toDateString()])
            ->assertCanSeeTableRecords([$overdue])->assertCanNotSeeTableRecords([$mine]);

        $this->assertSame('1', ReceivableResource::getNavigationBadge(), 'Badge: hanya yang belum lunas & lewat jatuh tempo di toko sendiri.');

        $this->actingAs($this->admin(), 'web');
        Livewire::test(ListReceivables::class)->assertCanSeeTableRecords([$mine, $theirs]);
    }

    // ------------------------------------------------------------- catat manual

    public function test_admin_records_a_manual_receivable_with_a_balanced_journal(): void
    {
        $this->actingAs($this->admin(), 'web');

        Livewire::test(ListReceivables::class)
            ->callTableAction('create_manual', data: [
                'customer_name' => 'Pak Budi', 'amount' => 750000, 'credit_account_id' => $this->revenue->id,
                'entry_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'notes' => 'Jasa tambahan',
            ])->assertHasNoTableActionErrors();

        $receivable = Receivable::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('unpaid', $receivable->status);
        $this->assertNotEmpty($receivable->receivable_number);
        $lines = JournalEntry::withoutGlobalScopes()->with('lines')->findOrFail($receivable->journal_entry_id)->lines;
        $this->assertEquals(750000, $lines->firstWhere('chart_of_account_id', ChartOfAccount::where('code', '1110')->value('id'))->debit);
        $this->assertEquals(750000, $lines->firstWhere('chart_of_account_id', $this->revenue->id)->credit);
    }

    public function test_manual_form_validation_and_server_side_account_check(): void
    {
        $this->actingAs($this->admin(), 'web');
        $base = ['customer_name' => 'Pak Budi', 'amount' => 1000, 'credit_account_id' => $this->revenue->id, 'entry_date' => now()->toDateString()];

        Livewire::test(ListReceivables::class)->callTableAction('create_manual', data: [...$base, 'customer_name' => ''])->assertHasTableActionErrors(['customer_name' => 'required']);
        Livewire::test(ListReceivables::class)->callTableAction('create_manual', data: [...$base, 'amount' => 0])->assertHasTableActionErrors(['amount']);
        Livewire::test(ListReceivables::class)->callTableAction('create_manual', data: [...$base, 'entry_date' => now()->addDays(3)->toDateString()])->assertHasTableActionErrors(['entry_date']);

        Livewire::test(ListReceivables::class)->callTableAction('create_manual', data: [...$base, 'credit_account_id' => ChartOfAccount::where('code', '1101')->value('id')]);
        $this->assertSame(0, Receivable::withoutGlobalScopes()->count(), 'Akun Kas tidak boleh dikredit, walau nilainya dikirim langsung.');
    }

    public function test_the_manual_button_follows_explicit_create_permission(): void
    {
        $this->actingAs($this->user('kasir'), 'web');
        $this->assertFalse((bool) ReceivableResource::canCreate());

        $this->actingAs($this->granted('create'), 'web');
        $this->assertTrue((bool) ReceivableResource::canCreate());

        $this->actingAs($this->admin(), 'web');
        $this->assertTrue((bool) ReceivableResource::canCreate());
    }

    // ------------------------------------------------------------- pelunasan

    public function test_receiving_payment_needs_explicit_permission_and_updates_the_receivable(): void
    {
        $receivable = $this->receivable();

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertFalse((bool) ReceivableResource::canReceive());
        Livewire::test(ListReceivables::class)->assertTableActionHidden('receive_payment', $this->row($receivable));

        $receiver = $this->granted('update');
        $this->actingAs($receiver, 'web');
        Livewire::test(ListReceivables::class)
            ->assertTableActionVisible('receive_payment', $this->row($receivable))
            ->callTableAction('receive_payment', $this->row($receivable), data: [
                'amount' => 200000, 'receive_account_id' => ChartOfAccount::where('code', '1101')->value('id'), 'payment_date' => now()->toDateString(),
            ])->assertHasNoTableActionErrors();

        $fresh = $receivable->fresh();
        $this->assertSame('partial', $fresh->status);
        $this->assertEquals(200000, $fresh->amount_paid);
        $payment = ReceivablePayment::firstOrFail();
        $this->assertNotEmpty($payment->receipt_number);
        $this->assertSame($receiver->id, $payment->created_by);
    }

    public function test_the_creator_cannot_receive_their_own_receivables_payment_and_overpayment_is_refused(): void
    {
        $creator = $this->granted('update');
        $receivable = $this->receivable([], $creator);
        $other = $this->granted('update');
        $cash = ChartOfAccount::where('code', '1101')->value('id');

        $this->actingAs($creator, 'web');
        Livewire::test(ListReceivables::class)->callTableAction('receive_payment', $this->row($receivable), data: ['amount' => 1000, 'receive_account_id' => $cash, 'payment_date' => now()->toDateString()]);
        $this->assertSame(0, ReceivablePayment::count(), 'Pemisahan tugas: pembuat tidak boleh menerima pelunasannya sendiri.');

        $this->actingAs($other, 'web');
        Livewire::test(ListReceivables::class)->callTableAction('receive_payment', $this->row($receivable), data: ['amount' => 600000, 'receive_account_id' => $cash, 'payment_date' => now()->toDateString()]);
        $this->assertSame(0, ReceivablePayment::count(), 'Melebihi sisa piutang ditolak.');

        Livewire::test(ListReceivables::class)->callTableAction('receive_payment', $this->row($receivable), data: ['amount' => 500000, 'receive_account_id' => $cash, 'payment_date' => now()->toDateString()]);
        $this->assertSame('paid', $receivable->fresh()->status);
    }

    public function test_only_open_receivables_offer_the_payment_action_and_a_non_cash_account_is_refused(): void
    {
        $this->actingAs($this->admin('Penerima'), 'web');
        $open = $this->receivable();
        $paid = $this->receivable(['customer_name' => 'Lunas']);
        app(ReceivableService::class)->recordPayment($paid, 500000, now(), auth()->id());

        Livewire::test(ListReceivables::class)
            ->assertTableActionVisible('receive_payment', $this->row($open))
            ->assertTableActionHidden('receive_payment', $this->row($paid->fresh()))
            ->callTableAction('receive_payment', $this->row($open), data: ['amount' => 1000, 'receive_account_id' => ChartOfAccount::where('code', '6110')->value('id'), 'payment_date' => now()->toDateString()]);

        $this->assertSame(0, ReceivablePayment::where('receivable_id', $open->id)->count(), 'Akun penerimaan harus Kas/Bank aktif.');
    }

    // ------------------------------------------------------------- koreksi

    public function test_void_payment_is_full_access_only_and_reverses_the_journal(): void
    {
        $receivable = $this->receivable();
        $payment = app(ReceivableService::class)->recordPayment($receivable, 300000, now(), $this->admin('Penerima')->id);

        $this->actingAs($this->granted('update'), 'web');
        Livewire::test(ListReceivables::class)->assertTableActionHidden('void_payment', $this->row($receivable));

        $this->actingAs($this->admin('Pembatal'), 'web');
        Livewire::test(ListReceivables::class)
            ->assertTableActionVisible('void_payment', $this->row($receivable))
            ->callTableAction('void_payment', $this->row($receivable), data: ['payment_id' => $payment->id, 'reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        Livewire::test(ListReceivables::class)
            ->callTableAction('void_payment', $this->row($receivable), data: ['payment_id' => $payment->id, 'reason' => 'Salah input']);

        $this->assertSame('unpaid', $receivable->fresh()->status);
        $this->assertEquals(0, $receivable->fresh()->amount_paid);
        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertTrue(JournalEntry::withoutGlobalScopes()->where('reference_type', 'reversal')->where('reference_id', $payment->journal_entry_id)->exists());
    }

    public function test_cancelling_a_manual_receivable_reverses_it_but_only_without_active_payments(): void
    {
        $this->actingAs($this->admin(), 'web');
        $clean = $this->receivable(['customer_name' => 'Bersih']);
        $withPayment = $this->receivable(['customer_name' => 'Ada Pelunasan']);
        app(ReceivableService::class)->recordPayment($withPayment, 1000, now(), $this->admin('Penerima')->id);

        Livewire::test(ListReceivables::class)
            ->assertTableActionVisible('cancel_receivable', $this->row($clean))
            ->assertTableActionHidden('cancel_receivable', $this->row($withPayment))
            ->callTableAction('cancel_receivable', $this->row($clean), data: ['reason' => 'Salah catat']);

        $fresh = $clean->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('Salah catat', $fresh->cancel_reason);
        $this->assertEquals(0, $fresh->remainingAmount());
        $this->assertTrue(JournalEntry::withoutGlobalScopes()->where('reference_type', 'reversal')->where('reference_id', $clean->journal_entry_id)->exists());

        Livewire::test(ListReceivables::class)->assertTableActionHidden('cancel_receivable', $this->row($clean->fresh()));
    }

    public function test_a_booking_receivable_cannot_be_cancelled_from_the_menu(): void
    {
        $this->actingAs($this->admin(), 'web');
        $fromBooking = $this->receivable(['customer_name' => 'Dari Booking']);
        Receivable::withoutGlobalScopes()->whereKey($fromBooking->id)->update(['source_type' => 'booking', 'source_id' => 999]);

        Livewire::test(ListReceivables::class)->assertTableActionHidden('cancel_receivable', $this->row($fromBooking->fresh()));
    }

    // ------------------------------------------------------------- rekonsiliasi & tampilan

    public function test_reconciliation_matches_the_ledger_with_the_subledger(): void
    {
        $this->receivable(['amount' => 500000]);
        $second = $this->receivable(['amount' => 250000, 'customer_name' => 'Kedua']);
        app(ReceivableService::class)->recordPayment($second, 100000, now(), $this->admin('Penerima')->id);

        $result = app(ReceivableService::class)->reconcile();

        $this->assertEquals(650000, $result['gl']);
        $this->assertEquals(650000, $result['subledger']);
        $this->assertEqualsWithDelta(0, $result['diff'], 0.005);

        $this->actingAs($this->admin(), 'web');
        Livewire::test(ListReceivables::class)->callTableAction('reconcile')->assertHasNoTableActionErrors();
    }

    public function test_the_detail_page_renders_for_viewers_in_scope(): void
    {
        $receivable = $this->receivable();
        $this->actingAs($this->admin(), 'web');

        Livewire::test(\App\Filament\Resources\ReceivableResource\Pages\ViewReceivable::class, ['record' => $receivable->getKey()])
            ->assertSuccessful()->assertSee($receivable->receivable_number);
    }

    public function test_receivables_cannot_be_edited_or_deleted_and_access_follows_menu_access(): void
    {
        $receivable = $this->receivable();

        $this->actingAs($this->admin(), 'web');
        $this->assertFalse(ReceivableResource::canEdit($receivable));
        $this->assertFalse(ReceivableResource::canDelete($receivable));
        $this->assertTrue(ReceivableResource::canViewAny());
        $this->assertTrue(ReceivableResource::canCorrect());

        $this->actingAs($this->user('kasir'), 'web');
        $this->assertTrue(ReceivableResource::canViewAny());
        $this->assertFalse(ReceivableResource::canCorrect());

        $this->actingAs($this->user('kasir', null, ['menu_access' => ['SomeOtherResource']]), 'web');
        $this->assertFalse(ReceivableResource::canViewAny());
    }
}
