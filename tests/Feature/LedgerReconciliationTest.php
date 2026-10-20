<?php

namespace Tests\Feature;

use App\Filament\Pages\LedgerReconciliationReport;
use App\Models\Asset;
use App\Models\ChartOfAccount;
use App\Models\ConsumableItem;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\JournalEntryService;
use App\Services\LedgerReconciliationService;
use App\Services\PayableService;
use App\Services\ReceivableService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Keuangan Fase 0 (2026-11-04): rekonsiliasi sub-buku ke akun kontrol (Piutang, Hutang, Aset Tetap, Persediaan), hapus lunak transaksi
 * keuangan, dan akun THR payroll yang terpisah. Waktu dibekukan: Rabu 7 Okt 2026.
 */
class LedgerReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Carbon::setTestNow('2026-10-07 09:10:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
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

    private function row(string $key): array
    {
        return app(LedgerReconciliationService::class)->run()->firstWhere('key', $key);
    }

    // ------------------------------------------------------------- piutang dan hutang

    public function test_receivables_match_the_control_account_through_creation_and_payment(): void
    {
        $service = app(ReceivableService::class);
        $revenue = $this->acct('4400');

        $receivable = $service->createWithJournal(['customer_name' => 'Pak Andi', 'store_id' => null, 'amount' => 500000], $revenue->id);

        $this->assertTrue($this->row('receivable')['ok']);
        $this->assertSame(500000.0, $this->row('receivable')['ledger']);

        $service->recordPayment($receivable, 200000, now(), null);

        $row = $this->row('receivable');
        $this->assertTrue($row['ok']);
        $this->assertSame(300000.0, $row['subledger']);
    }

    public function test_a_receivable_without_a_journal_shows_up_as_a_difference(): void
    {
        app(ReceivableService::class)->create(['customer_name' => 'Tanpa jurnal', 'store_id' => null, 'amount' => 100000]);

        $row = $this->row('receivable');

        $this->assertFalse($row['ok']);
        $this->assertSame(-100000.0, $row['difference'], 'Buku Besar 0 dikurangi sub-buku 100.000.');
    }

    public function test_payables_match_after_a_partial_payment(): void
    {
        $service = app(PayableService::class);
        $expense = $this->acct('6410');

        $payable = $service->createWithJournal(['supplier_name' => 'PT Uji', 'store_id' => null, 'amount' => 1000000, 'due_date' => now()->addDays(10)->toDateString()], $expense->id);
        $service->recordPayment($payable, 400000, now(), null);

        $row = $this->row('payable');

        $this->assertTrue($row['ok']);
        $this->assertSame(600000.0, $row['ledger']);
    }

    // ------------------------------------------------------------- aset dan persediaan

    public function test_fixed_assets_are_compared_per_account(): void
    {
        $machine = $this->acct('1210');
        Asset::create(['asset_tag' => 'AST-T1', 'name' => 'Mesin vakum', 'category' => 'Mesin', 'status' => 'aktif', 'purchase_date' => '2026-01-01', 'purchase_cost' => 5000000, 'chart_of_account_id' => $machine->id]);
        Asset::create(['asset_tag' => 'AST-T2', 'name' => 'Mesin dijual', 'category' => 'Mesin', 'status' => 'dijual', 'purchase_date' => '2026-01-01', 'purchase_cost' => 9000000, 'chart_of_account_id' => $machine->id]);

        $before = $this->row('asset_1210');
        $this->assertFalse($before['ok']);
        $this->assertSame(5000000.0, $before['subledger'], 'Aset yang sudah dijual tidak dihitung.');

        $service = app(JournalEntryService::class);
        $entry = $service->create(['entry_date' => '2026-10-01', 'description' => 'Pembelian mesin'], [
            ['chart_of_account_id' => $machine->id, 'debit' => 5000000],
            ['chart_of_account_id' => $this->acct('3100')->id, 'credit' => 5000000],
        ]);
        $service->post($entry, null);

        $this->assertTrue($this->row('asset_1210')['ok']);
    }

    public function test_unrecorded_consumable_stock_is_reported_against_the_inventory_account(): void
    {
        ConsumableItem::create(['name' => 'Sarung tangan', 'code' => 'CI-T1', 'category' => 'APD', 'unit' => 'pcs', 'current_stock' => 10, 'unit_cost' => 5000]);

        $row = $this->row('consumable');

        $this->assertFalse($row['ok']);
        $this->assertSame(50000.0, $row['subledger']);
        $this->assertSame(0.0, $row['ledger']);
        $this->assertNull(app(LedgerReconciliationService::class)->run(null, 999)->firstWhere('key', 'consumable'), 'Persediaan tidak dibandingkan per toko.');
    }

    // ------------------------------------------------------------- halaman

    public function test_the_report_page_renders_for_admin(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'a@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');
        app(ReceivableService::class)->create(['customer_name' => 'Tanpa jurnal', 'store_id' => null, 'amount' => 100000]);

        Livewire::test(LedgerReconciliationReport::class)->assertSuccessful()
            ->assertSee('Piutang Usaha')
            ->assertSee('berselisih');
    }

    // ------------------------------------------------------------- hapus lunak

    public function test_a_deleted_finance_transaction_is_kept_for_the_record(): void
    {
        $category = \App\Models\FinanceCategory::create(['name' => 'Bensin', 'type' => 'out', 'chart_of_account_id' => $this->acct('6440')->id, 'is_active' => true]);
        $transaction = FinanceTransaction::create([
            'finance_category_id' => $category->id, 'type' => 'out', 'amount' => 50000, 'transaction_date' => '2026-10-05', 'description' => 'Bensin',
        ]);

        $transaction->delete();

        $this->assertNull(FinanceTransaction::find($transaction->id));
        $this->assertNotNull(FinanceTransaction::withTrashed()->find($transaction->id), 'Barisnya tetap tersimpan.');
        $this->assertDatabaseHas('finance_transactions', ['id' => $transaction->id]);
    }
}
