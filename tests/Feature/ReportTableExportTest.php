<?php

namespace Tests\Feature;

use App\Exports\SimpleTableExport;
use App\Filament\Pages\BudgetVsActualReport;
use App\Filament\Pages\CashBalanceReport;
use App\Filament\Pages\LedgerReconciliationReport;
use App\Models\Budget;
use App\Models\ChartOfAccount;
use App\Models\User;
use App\Services\JournalEntryService;
use Database\Seeders\ChartOfAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ekspor Excel dan PDF untuk Saldo Kas & Bank, Anggaran vs Realisasi, dan Rekonsiliasi Sub-Buku (ExportsReportTable): isi data
 * ekspor sama dengan tampilan, berkas bernama sesuai laporan, dan setiap ekspor tercatat di riwayat aktivitas.
 * "Hari ini" dibekukan di 20 Oktober 2026.
 */
class ReportTableExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        $this->seed(ChartOfAccountSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->actingAs(tap(User::create(['name' => 'Admin', 'email' => uniqid() . '@test.local', 'password' => 'x', 'is_active' => true]), fn (User $u) => $u->assignRole('super_admin')), 'web');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(string $code): ChartOfAccount
    {
        return ChartOfAccount::where('code', $code)->firstOrFail();
    }

    private function post(string $debitCode, string $creditCode, float $amount, string $date = '2026-10-10'): void
    {
        $service = app(JournalEntryService::class);
        $entry = $service->create(['entry_date' => $date, 'store_id' => null, 'description' => 'Tes ekspor', 'created_by' => null], [
            ['chart_of_account_id' => $this->account($debitCode)->id, 'debit' => $amount],
            ['chart_of_account_id' => $this->account($creditCode)->id, 'credit' => $amount],
        ]);
        $service->post($entry, null);
    }

    /** Data ekspor sebuah halaman (metodenya protected): dipanggil dalam konteks halaman itu. */
    private function exportData($page): array
    {
        return (fn () => $this->reportExportData())->call($page->instance());
    }

    // ------------------------------------------------------------- Saldo Kas & Bank

    public function test_cash_balance_export_lists_each_account_and_the_total(): void
    {
        $this->post('1102', '4100', 100000);
        $this->post('1102', '4100', 50000);

        $data = $this->exportData(Livewire::test(CashBalanceReport::class));

        $this->assertSame('saldo-kas-bank', $data['key']);
        $bank = collect($data['rows'])->firstWhere(0, '1102');
        $this->assertNotNull($bank, 'Akun 1102 ada di ekspor.');
        $this->assertSame(150000.0, $bank[4]);
        $this->assertSame(150000.0, $data['totals'][4]);
        $this->assertSame(['Kode', 'Akun', 'Rekening', 'Toko', 'Saldo'], $data['headings']);
    }

    // ------------------------------------------------------------- Anggaran vs Realisasi

    public function test_budget_export_carries_budget_actual_remaining_and_status(): void
    {
        Budget::create(['period_month' => '2026-10-01', 'store_id' => null, 'chart_of_account_id' => $this->account('6410')->id, 'amount' => 1000000]);
        $this->post('6410', '1102', 600000);

        $data = $this->exportData(Livewire::test(BudgetVsActualReport::class));

        $row = collect($data['rows'])->firstWhere(0, '6410');
        $this->assertNotNull($row);
        $this->assertSame([1000000.0, 600000.0, 400000.0, '60%', 'Aman'], [$row[2], $row[3], $row[4], $row[5], $row[6]]);
        $this->assertSame(1000000.0, $data['totals'][2]);
        $this->assertSame(600000.0, $data['totals'][3]);
        $this->assertStringContainsString('Oktober 2026', $data['subtitle']);
    }

    public function test_an_account_over_budget_is_exported_as_exceeded(): void
    {
        Budget::create(['period_month' => '2026-10-01', 'store_id' => null, 'chart_of_account_id' => $this->account('6410')->id, 'amount' => 500000]);
        $this->post('6410', '1102', 700000);

        $row = collect($this->exportData(Livewire::test(BudgetVsActualReport::class))['rows'])->firstWhere(0, '6410');

        $this->assertSame('Melebihi anggaran', $row[6]);
        $this->assertSame(-200000.0, $row[4]);
    }

    // ------------------------------------------------------------- Rekonsiliasi Sub-Buku

    public function test_ledger_reconciliation_export_has_one_row_per_check(): void
    {
        $page = Livewire::test(LedgerReconciliationReport::class);
        $checks = $page->instance()->getChecks();

        $data = $this->exportData($page);

        $this->assertSame('rekonsiliasi-sub-buku', $data['key']);
        $this->assertCount($checks->count(), $data['rows']);
        foreach ($data['rows'] as $row) {
            $this->assertContains($row[5], ['Cocok', 'Berselisih']);
        }
        $this->assertArrayNotHasKey('totals', $data);
    }

    // ------------------------------------------------------------- tombol

    public function test_the_buttons_download_named_files_and_are_logged(): void
    {
        Excel::fake();

        $cash = Livewire::test(CashBalanceReport::class);
        $cash->callAction('exportExcel')->assertHasNoActionErrors();
        Excel::assertDownloaded('saldo-kas-bank-20261020-090000.xlsx');
        $cash->callAction('exportPdf')->assertFileDownloaded('saldo-kas-bank-20261020-090000.pdf');

        $budget = Livewire::test(BudgetVsActualReport::class);
        $budget->callAction('exportPdf')->assertFileDownloaded('anggaran-vs-realisasi-20261020-090000.pdf');

        $ledger = Livewire::test(LedgerReconciliationReport::class);
        $ledger->callAction('exportPdf')->assertFileDownloaded('rekonsiliasi-sub-buku-20261020-090000.pdf');

        $this->assertSame(4, Activity::where('log_name', 'report_export')->count());
        $this->assertTrue(Activity::where('log_name', 'report_export')->get()->contains(fn ($a) => ($a->properties['report'] ?? null) === 'saldo-kas-bank' && ($a->properties['format'] ?? null) === 'xlsx'));
    }

    public function test_the_existing_sync_button_is_still_on_the_reconciliation_page(): void
    {
        Livewire::test(LedgerReconciliationReport::class)->assertActionExists('syncInventory')->assertActionExists('exportExcel');
    }

    // ------------------------------------------------------------- kelas ekspor

    public function test_the_excel_class_appends_the_totals_row_and_styles_it(): void
    {
        $export = new SimpleTableExport(['headings' => ['A', 'B'], 'rows' => [['x', 1.0], ['y', 2.0]], 'totals' => ['Total', 3.0]]);

        $this->assertSame(['A', 'B'], $export->headings());
        $this->assertSame([['x', 1.0], ['y', 2.0], ['Total', 3.0]], $export->array());

        $styles = $export->styles(new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet());
        $this->assertArrayHasKey(1, $styles);
        $this->assertArrayHasKey(4, $styles, 'Baris total (data + judul + 1) ditebalkan.');

        $plain = new SimpleTableExport(['headings' => ['A'], 'rows' => [['x']]]);
        $this->assertSame([['x']], $plain->array());
        $this->assertArrayNotHasKey(3, $plain->styles(new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet()));
    }
}
