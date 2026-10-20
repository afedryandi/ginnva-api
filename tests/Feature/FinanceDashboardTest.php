<?php

namespace Tests\Feature;

use App\Filament\Pages\FinanceDashboard;
use App\Models\ChartOfAccount;
use App\Models\Receivable;
use App\Models\Store;
use App\Models\User;
use App\Services\AccountingPeriodService;
use App\Services\FinanceDashboardService;
use App\Services\JournalEntryService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Keuangan Fase 6 (2026-11-09): Dasbor Keuangan dan butir tambahan daftar periksa tutup bulan. Waktu dibekukan: Rabu 7 Okt 2026.
 */
class FinanceDashboardTest extends TestCase
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

    private function journal(string $date, string $debitCode, string $creditCode, float $amount): void
    {
        $service = app(JournalEntryService::class);
        $entry = $service->create(
            ['entry_date' => $date, 'store_id' => null, 'description' => 'Uji', 'created_by' => null],
            [
                ['chart_of_account_id' => ChartOfAccount::where('code', $debitCode)->value('id'), 'debit' => $amount],
                ['chart_of_account_id' => ChartOfAccount::where('code', $creditCode)->value('id'), 'credit' => $amount],
            ]
        );
        $service->post($entry, null);
    }

    public function test_summary_reports_cash_revenue_and_open_receivables(): void
    {
        $this->journal('2026-09-10', '1101', '4400', 400000);
        $this->journal('2026-10-03', '1101', '4400', 1000000);
        $this->journal('2026-10-04', '6440', '1101', 250000);

        Receivable::create([
            'receivable_number' => 'RCV-T1', 'customer_name' => 'Pak Andi', 'store_id' => null, 'amount' => 500000, 'amount_paid' => 100000,
            'due_date' => '2026-10-01', 'status' => 'partial',
        ]);

        $s = app(FinanceDashboardService::class)->summary();

        $this->assertSame(1150000.0, $s['cash_total']);
        $this->assertSame(1000000.0, $s['month']['revenue']);
        $this->assertSame(750000.0, $s['month']['net_income']);
        $this->assertSame(400000.0, $s['month']['prev_revenue']);
        $this->assertSame(400000.0, $s['receivable']['outstanding']);
        $this->assertSame(400000.0, $s['receivable']['overdue']);
        $this->assertSame(1, $s['receivable']['overdue_count']);
        $this->assertSame(0.0, $s['payable']['outstanding']);
        $this->assertFalse($s['previous_month']['closed']);
        $this->assertNull($s['year_to_close'], 'Oktober: belum waktunya mengingatkan tutup tahun.');
    }

    public function test_year_to_close_is_flagged_after_january(): void
    {
        Carbon::setTestNow('2026-02-10 09:00:00');
        $this->journal('2025-06-10', '1101', '4400', 100000);

        $this->assertSame(2025, app(FinanceDashboardService::class)->summary()['year_to_close']);
    }

    public function test_checklist_flags_unresolved_cash_count_differences(): void
    {
        $store = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $account = \App\Models\PaymentAccount::create([
            'name' => 'Kas Toko A', 'kind' => 'kas', 'chart_of_account_id' => ChartOfAccount::where('code', '1101')->value('id'), 'store_id' => $store->id, 'is_active' => true,
        ]);
        \App\Models\CashCount::create([
            'payment_account_id' => $account->id, 'store_id' => $store->id, 'count_date' => '2026-09-20', 'counted_amount' => 90000, 'system_balance' => 100000, 'difference' => -10000,
        ]);

        $keys = collect(app(AccountingPeriodService::class)->checklist(Carbon::parse('2026-09-01')))->pluck('key');

        $this->assertTrue($keys->contains('cash_count'));
    }

    public function test_the_page_renders_for_admin(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'a@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');
        $this->journal('2026-10-03', '1101', '4400', 1000000);

        Livewire::test(FinanceDashboard::class)->assertSuccessful()
            ->assertSee('Kas & Bank')
            ->assertSee('Perlu tindakan');
    }
}
