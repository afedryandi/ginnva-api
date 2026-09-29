<?php

namespace Tests\Feature;

use App\Filament\Pages\PayableAgingReport;
use App\Filament\Pages\ReceivableAgingReport;
use App\Models\ChartOfAccount;
use App\Models\Supplier;
use App\Services\PayableService;
use App\Services\ReceivableService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit Umur Hutang/Piutang 2026-09-29: pengelompokan berdasarkan supplier_id/customer_id
 * (bukan teks nama) supaya nama yang beda ejaan/kapitalisasi antar tagihan tidak terpecah jadi
 * beberapa baris. (Belum pernah dijalankan lokal -- tidak ada PHP; cek hasil CI.)
 */
class AgingReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
    }

    public function test_payable_aging_groups_by_supplier_id_despite_differing_name_snapshots(): void
    {
        $supplier = Supplier::create(['name' => 'PT Sumber Jaya', 'is_active' => true]);
        $expense = ChartOfAccount::where('type', 'beban_operasional')->where('is_postable', true)->firstOrFail();
        $service = app(PayableService::class);

        $service->createWithJournal(['supplier_name' => 'PT Sumber Jaya', 'supplier_id' => $supplier->id, 'amount' => 100_000], $expense->id);
        $service->createWithJournal(['supplier_name' => 'pt sumber jaya', 'supplier_id' => $supplier->id, 'amount' => 200_000], $expense->id);

        $aging = (new PayableAgingReport())->getAging();

        $this->assertCount(1, $aging['rows'], 'Supplier yang sama (supplier_id sama) harus jadi 1 baris walau nama snapshot beda kapitalisasi.');
        $this->assertEquals(300_000.0, (float) $aging['rows']->first()->total);
    }

    public function test_receivable_aging_groups_by_customer_id(): void
    {
        $customer = \App\Models\Customer::create(['name' => 'Budi', 'phone_number' => '081200000099']);
        $revenue = ChartOfAccount::where('type', 'pendapatan_lain')->where('is_postable', true)->firstOrFail();
        $service = app(ReceivableService::class);

        $service->createWithJournal(['customer_name' => 'Budi', 'customer_id' => $customer->id, 'amount' => 50_000], $revenue->id);
        $service->createWithJournal(['customer_name' => 'BUDI', 'customer_id' => $customer->id, 'amount' => 75_000], $revenue->id);

        $aging = (new ReceivableAgingReport())->getAging();

        $this->assertCount(1, $aging['rows']);
        $this->assertEquals(125_000.0, (float) $aging['rows']->first()->total);
    }

    public function test_payable_aging_export_totals_match_summary(): void
    {
        $supplier = Supplier::create(['name' => 'PT Uji Ekspor', 'is_active' => true]);
        $expense = ChartOfAccount::where('type', 'beban_operasional')->where('is_postable', true)->firstOrFail();
        app(PayableService::class)->createWithJournal(['supplier_name' => 'PT Uji Ekspor', 'supplier_id' => $supplier->id, 'amount' => 400_000], $expense->id);

        $page = new PayableAgingReport();
        $aging = $page->getAging();

        $rows = (new \App\Exports\PayableAgingExport($aging, 'Semua Toko'))->array();
        $totalRow = end($rows);

        $this->assertSame('Total', $totalRow[1]);
        $this->assertEquals($aging['totals']['total'], $totalRow[7]);
        $this->assertSame(1, $aging['supplier_count']);
    }

    public function test_payable_aging_falls_back_to_name_grouping_without_supplier_id(): void
    {
        $expense = ChartOfAccount::where('type', 'beban_operasional')->where('is_postable', true)->firstOrFail();
        app(PayableService::class)->createWithJournal(['supplier_name' => 'Tanpa Master', 'amount' => 10_000], $expense->id);

        $aging = (new PayableAgingReport())->getAging();

        $this->assertCount(1, $aging['rows']);
        $this->assertSame('Tanpa Master', $aging['rows']->first()->supplier);
    }
}
