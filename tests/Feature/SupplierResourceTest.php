<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\RecurringBillTemplate;
use App\Models\Supplier;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit Supplier 2026-09-29: guard hapus juga memeriksa Template Tagihan Rutin (sebelumnya
 * hanya memeriksa Payable, supplier bisa terhapus & template diam-diam kehilangan taut).
 * (Belum pernah dijalankan lokal -- tidak ada PHP; cek hasil CI.)
 */
class SupplierResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountSeeder::class);
    }

    public function test_supplier_in_use_by_recurring_template_is_flagged(): void
    {
        $supplier = Supplier::create(['name' => 'PT Sewa Gedung', 'is_active' => true]);
        $expense = ChartOfAccount::where('type', 'beban_operasional')->where('is_postable', true)->firstOrFail();

        RecurringBillTemplate::create([
            'name' => 'Sewa Bulanan',
            'supplier_name' => $supplier->name,
            'supplier_id' => $supplier->id,
            'chart_of_account_id' => $expense->id,
            'amount' => 1_000_000,
            'day_of_month' => 1,
            'next_run_date' => now()->addMonth()->startOfMonth(),
            'is_active' => true,
        ]);

        $this->assertTrue($supplier->fresh()->isInUse());
    }

    public function test_unused_supplier_is_not_in_use(): void
    {
        $supplier = Supplier::create(['name' => 'PT Belum Dipakai', 'is_active' => true]);

        $this->assertFalse($supplier->isInUse());
    }

    public function test_similar_supplier_name_is_flagged(): void
    {
        Supplier::create(['name' => 'PT Sumber Jaya', 'is_active' => true]);

        $ref = new \ReflectionMethod(\App\Filament\Resources\SupplierResource::class, 'findSimilarSupplierName');
        $ref->setAccessible(true);

        $this->assertSame('PT Sumber Jaya', $ref->invoke(null, 'PT Sumber Jayaa', null));
        $this->assertNull($ref->invoke(null, 'PT Sumber Jaya', null)); // persis sama -> ditangkap unique, bukan peringatan ini
        $this->assertNull($ref->invoke(null, 'CV Berbeda Total', null));
    }
}
