<?php

namespace Tests\Feature;

use App\Filament\Pages\YearEndClosingPage;
use App\Models\AccountingPeriod;
use App\Models\ChartOfAccount;
use App\Models\Store;
use App\Models\User;
use App\Models\YearEndClosing;
use App\Services\FinancialStatementService;
use App\Services\JournalEntryService;
use App\Services\YearEndClosingService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Keuangan Fase 6 (2026-11-09): penutupan tahun buku. Waktu dibekukan: Rabu 7 Okt 2026, jadi 2025 adalah tahun yang sudah lewat.
 */
class YearEndClosingTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Role::findOrCreate('super_admin', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Carbon::setTestNow('2026-10-07 09:10:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->storeA = Store::create(['city' => 'Jakarta', 'address' => 'Jl. A', 'name' => 'Toko A', 'is_active' => true]);
        $this->storeB = Store::create(['city' => 'Bandung', 'address' => 'Jl. B', 'name' => 'Toko B', 'is_active' => true]);
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

    private function post(string $date, ?int $storeId, string $debitCode, string $creditCode, float $amount): void
    {
        $service = app(JournalEntryService::class);
        $entry = $service->create(
            ['entry_date' => $date, 'store_id' => $storeId, 'description' => 'Uji', 'created_by' => null],
            [['chart_of_account_id' => $this->acct($debitCode)->id, 'debit' => $amount], ['chart_of_account_id' => $this->acct($creditCode)->id, 'credit' => $amount]]
        );
        $service->post($entry, null);
    }

    /** 2025: Toko A untung 700.000 (pendapatan 1.000.000, beban 300.000); Toko B rugi 100.000; tanpa toko: pendapatan 50.000. */
    private function seedYear(): void
    {
        $this->post('2025-03-10', $this->storeA->id, '1101', '4400', 1000000);
        $this->post('2025-04-10', $this->storeA->id, '6440', '1101', 300000);
        $this->post('2025-05-10', $this->storeB->id, '6440', '1101', 100000);
        $this->post('2025-06-10', null, '1101', '4400', 50000);
    }

    private function profit(string $from, string $to, ?int $storeId = null): float
    {
        return app(FinancialStatementService::class)->incomeStatement(Carbon::parse($from), Carbon::parse($to), $storeId)['laba_bersih'];
    }

    public function test_closing_moves_the_profit_to_retained_earnings_and_zeroes_profit_loss_accounts(): void
    {
        $this->seedYear();
        $statements = app(FinancialStatementService::class);

        $closing = app(YearEndClosingService::class)->close(2025, null);

        $this->assertSame(3, $closing->entries_count, 'Satu jurnal per toko ditambah satu tanpa toko.');
        $this->assertSame('650000.00', $closing->net_income);

        // Laba Rugi tahun 2025 tetap menampilkan angka sebenarnya.
        $this->assertSame(650000.0, $this->profit('2025-01-01', '2025-12-31'));
        $this->assertSame(700000.0, $this->profit('2025-01-01', '2025-12-31', $this->storeA->id));
        $this->assertSame(-100000.0, $this->profit('2025-01-01', '2025-12-31', $this->storeB->id));

        // Neraca 2026 seimbang, dan laba tahun lalu pindah ke Laba Ditahan (bukan dihitung dobel).
        $sheet = $statements->balanceSheet(Carbon::parse('2026-10-07'));
        $this->assertTrue($sheet['is_balanced']);
        $this->assertSame(650000.0, $sheet['modal']['rows']->firstWhere(fn ($r) => $r['account']->code === '3200')['balance']);
        $this->assertSame(0.0, $sheet['modal']['laba_tahun_lalu'] + $sheet['modal']['laba_tahun_berjalan']);

        // Neraca Saldo 2026 seimbang.
        $this->assertTrue($statements->trialBalance(Carbon::parse('2026-10-07'), null, null, true)['is_balanced']);

        // Neraca per toko tetap seimbang.
        $this->assertTrue($statements->balanceSheet(Carbon::parse('2026-10-07'), $this->storeA->id)['is_balanced']);
    }

    public function test_balance_sheet_stays_balanced_before_and_after_closing(): void
    {
        $this->seedYear();
        $this->post('2026-02-01', $this->storeA->id, '1101', '4400', 200000);
        $statements = app(FinancialStatementService::class);

        $before = $statements->balanceSheet(Carbon::parse('2026-10-07'));
        app(YearEndClosingService::class)->close(2025, null);
        $after = $statements->balanceSheet(Carbon::parse('2026-10-07'));

        $this->assertTrue($before['is_balanced']);
        $this->assertTrue($after['is_balanced']);
        $this->assertSame($before['total_kewajiban_modal'], $after['total_kewajiban_modal']);
        $this->assertSame(200000.0, $after['modal']['laba_tahun_berjalan']);
        $this->assertSame(200000.0, $this->profit('2026-01-01', '2026-12-31'), 'Laba 2026 tidak terpengaruh.');
    }

    public function test_a_year_cannot_be_closed_twice_or_while_unfinished_or_with_drafts_or_in_a_closed_december(): void
    {
        $this->seedYear();
        $service = app(YearEndClosingService::class);

        $this->assertNotEmpty($service->blockers(2026), 'Tahun berjalan belum bisa ditutup.');

        $draftService = app(JournalEntryService::class);
        $draftService->create(
            ['entry_date' => '2025-12-01', 'store_id' => null, 'description' => 'Draft', 'created_by' => null],
            [['chart_of_account_id' => $this->acct('1101')->id, 'debit' => 10], ['chart_of_account_id' => $this->acct('4400')->id, 'credit' => 10]]
        );
        $this->assertStringContainsString('Draft', implode(' ', $service->blockers(2025)));
        \App\Models\JournalEntry::withoutGlobalScopes()->where('status', 'draft')->delete();

        $user = User::create(['name' => 'Admin', 'email' => 'a@test.local', 'password' => 'x']);
        AccountingPeriod::create(['period_month' => '2025-12-01', 'closed_by' => $user->id, 'closed_at' => now()]);
        $this->assertStringContainsString('Desember', implode(' ', $service->blockers(2025)));
        AccountingPeriod::query()->delete();

        $service->close(2025, null);
        $this->assertStringContainsString('sudah ditutup', implode(' ', $service->blockers(2025)));
        $this->expectException(\RuntimeException::class);
        $service->close(2025, null);
    }

    public function test_voiding_restores_the_open_state_and_the_year_can_be_closed_again(): void
    {
        $this->seedYear();
        $service = app(YearEndClosingService::class);
        $statements = app(FinancialStatementService::class);

        $closing = $service->close(2025, null);
        $service->void($closing, null, 'Salah hitung');

        $this->assertFalse($closing->fresh()->isActive());
        $this->assertSame(650000.0, $this->profit('2025-01-01', '2025-12-31'), 'Pembalik jurnal penutup tidak mengubah Laba Rugi.');
        $this->assertSame(650000.0, $statements->balanceSheet(Carbon::parse('2026-10-07'))['modal']['laba_tahun_lalu']);
        $this->assertTrue($statements->balanceSheet(Carbon::parse('2026-10-07'))['is_balanced']);

        $again = $service->close(2025, null);
        $this->assertSame('650000.00', $again->net_income, 'Penutupan ulang tidak menghitung jurnal pembalik.');
        $this->assertTrue($statements->balanceSheet(Carbon::parse('2026-10-07'))['is_balanced']);
    }

    public function test_voiding_requires_a_reason(): void
    {
        $this->seedYear();
        $service = app(YearEndClosingService::class);
        $closing = $service->close(2025, null);

        $this->expectException(\RuntimeException::class);
        $service->void($closing, null, '  ');
    }

    public function test_budget_actuals_ignore_closing_entries(): void
    {
        $this->seedYear();
        app(YearEndClosingService::class)->close(2025, null);

        $this->assertSame(YearEndClosing::closedYears(), [2025]);
        $row = \App\Models\JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereRaw(YearEndClosingService::EXCLUDE_CLOSING_SQL)
            ->where('journal_entries.entry_date', '2025-12-31')
            ->count();
        $this->assertSame(0, $row);
    }

    public function test_the_page_is_for_full_access_only_and_runs_the_action(): void
    {
        $this->seedYear();
        $admin = User::create(['name' => 'Admin', 'email' => 'a@test.local', 'password' => 'x']);
        $admin->assignRole('super_admin');
        $this->actingAs($admin, 'web');

        Livewire::test(YearEndClosingPage::class)->assertSuccessful()->assertSee('Belum ada tahun buku yang ditutup')
            ->callAction('closeYear', ['year' => 2025]);

        $this->assertSame([2025], YearEndClosing::closedYears());
    }
}
